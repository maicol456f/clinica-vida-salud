-- =====================================================================
CREATE EXTENSION IF NOT EXISTS btree_gist;   -- necesaria para la restricción de exclusión de citas
-- Secuencias para códigos legibles
CREATE SEQUENCE seq_paciente START 1;
CREATE SEQUENCE seq_medico   START 1;
CREATE SEQUENCE seq_cita     START 1;
-- ---------------------------------------------------------------------
-- CICLO 1: seguridad, configuración, pacientes y citas
-- ---------------------------------------------------------------------
CREATE TABLE roles (
    id_rol       SERIAL PRIMARY KEY,
    nombre       VARCHAR(30)  NOT NULL UNIQUE,
    descripcion  VARCHAR(150)
);
CREATE TABLE usuarios (
    id_usuario      SERIAL PRIMARY KEY,
    id_rol          INT          NOT NULL REFERENCES roles(id_rol),
    nombre_completo VARCHAR(120) NOT NULL,
    username        VARCHAR(40)  NOT NULL UNIQUE,
    correo          VARCHAR(120) NOT NULL UNIQUE,
    password_hash   VARCHAR(255) NOT NULL,          -- generado con password_hash() de PHP
    estado          BOOLEAN      NOT NULL DEFAULT TRUE,
    creado_en       TIMESTAMP    NOT NULL DEFAULT now()
);
CREATE TABLE tokens_recuperacion (
    id_token    SERIAL PRIMARY KEY,
    id_usuario  INT          NOT NULL REFERENCES usuarios(id_usuario),
    token_hash  VARCHAR(255) NOT NULL,
    expira_en   TIMESTAMP    NOT NULL,
    usado       BOOLEAN      NOT NULL DEFAULT FALSE
);
CREATE TABLE especialidades (
    id_especialidad    SERIAL PRIMARY KEY,
    nombre             VARCHAR(80)  NOT NULL,
    nombre_normalizado VARCHAR(80)  NOT NULL UNIQUE,   -- minúsculas y sin acentos
    descripcion        VARCHAR(200),
    estado             BOOLEAN      NOT NULL DEFAULT TRUE
);
CREATE TABLE medicos (
    id_medico     SERIAL PRIMARY KEY,
    codigo        VARCHAR(10)  NOT NULL UNIQUE DEFAULT ('MED-' || lpad(nextval('seq_medico')::text, 3, '0')),
    nro_registro  VARCHAR(30)  NOT NULL UNIQUE,
    nombres       VARCHAR(80)  NOT NULL,
    apellidos     VARCHAR(80)  NOT NULL,
    telefono      VARCHAR(20),
    correo        VARCHAR(120),
    estado        BOOLEAN      NOT NULL DEFAULT TRUE,
    id_usuario    INT UNIQUE REFERENCES usuarios(id_usuario)
);
CREATE TABLE medico_especialidad (
    id_medico        INT NOT NULL REFERENCES medicos(id_medico),
    id_especialidad  INT NOT NULL REFERENCES especialidades(id_especialidad),
    PRIMARY KEY (id_medico, id_especialidad)
);
CREATE TABLE horarios_atencion (
    id_horario    SERIAL PRIMARY KEY,
    id_medico     INT      NOT NULL REFERENCES medicos(id_medico),
    dia_semana    SMALLINT NOT NULL CHECK (dia_semana BETWEEN 1 AND 7),   -- 1 = lunes
    hora_inicio   TIME     NOT NULL,
    hora_fin      TIME     NOT NULL,
    duracion_min  SMALLINT NOT NULL CHECK (duracion_min > 0),
    estado        BOOLEAN  NOT NULL DEFAULT TRUE,
    CONSTRAINT ck_horario_rango CHECK (hora_fin > hora_inicio)
);
CREATE TABLE pacientes (
    id_paciente       SERIAL PRIMARY KEY,
    codigo            VARCHAR(12)  NOT NULL UNIQUE DEFAULT ('PAC-' || lpad(nextval('seq_paciente')::text, 6, '0')),
    ci                VARCHAR(20)  UNIQUE,
    nombres           VARCHAR(80)  NOT NULL,
    apellidos         VARCHAR(80)  NOT NULL,
    fecha_nacimiento  DATE         NOT NULL,
    sexo              CHAR(1)      CHECK (sexo IN ('M','F')),
    telefono          VARCHAR(20)  NOT NULL,
    correo            VARCHAR(120),
    direccion         VARCHAR(200),
    estado            BOOLEAN      NOT NULL DEFAULT TRUE,
    id_usuario        INT UNIQUE REFERENCES usuarios(id_usuario),
    creado_en         TIMESTAMP    NOT NULL DEFAULT now()
);
CREATE TABLE citas (
    id_cita             SERIAL PRIMARY KEY,
    codigo              VARCHAR(12) NOT NULL UNIQUE DEFAULT ('CM-' || lpad(nextval('seq_cita')::text, 4, '0')),
    id_paciente         INT  NOT NULL REFERENCES pacientes(id_paciente),
    id_medico           INT  NOT NULL REFERENCES medicos(id_medico),
    id_especialidad     INT  NOT NULL REFERENCES especialidades(id_especialidad),
    fecha               DATE NOT NULL,
    hora_inicio         TIME NOT NULL,
    hora_fin            TIME NOT NULL,
    motivo              VARCHAR(200),
    estado              VARCHAR(15) NOT NULL DEFAULT 'Programada',
    motivo_cancelacion  VARCHAR(200),
    creado_por          INT REFERENCES usuarios(id_usuario),
    creado_en           TIMESTAMP NOT NULL DEFAULT now(),
    actualizado_en      TIMESTAMP NOT NULL DEFAULT now(),
    CONSTRAINT ck_cita_estado CHECK (estado IN ('Programada','Confirmada','Atendida','Cancelada','Inasistencia')),
    CONSTRAINT ck_cita_rango  CHECK (hora_fin > hora_inicio),
    -- Un médico no puede tener citas superpuestas (las canceladas no ocupan disponibilidad)
    CONSTRAINT ex_cita_sin_traslape EXCLUDE USING gist (
        id_medico WITH =,
        tsrange(fecha + hora_inicio, fecha + hora_fin) WITH &&
    ) WHERE (estado <> 'Cancelada')
);
CREATE INDEX idx_citas_paciente ON citas(id_paciente);
CREATE INDEX idx_citas_medico_fecha ON citas(id_medico, fecha);
CREATE TABLE cita_historial (
    id_historial     SERIAL PRIMARY KEY,
    id_cita          INT         NOT NULL REFERENCES citas(id_cita),
    estado_anterior  VARCHAR(15),
    estado_nuevo     VARCHAR(15) NOT NULL,
    fecha_hora       TIMESTAMP   NOT NULL DEFAULT now(),
    id_usuario       INT REFERENCES usuarios(id_usuario),
    observacion      VARCHAR(200)
);
-- ---------------------------------------------------------------------
-- CICLO 2: historia clínica, laboratorio, notificaciones y bloqueos
-- ---------------------------------------------------------------------
CREATE TABLE historias_clinicas (
    id_historia           SERIAL PRIMARY KEY,
    id_paciente           INT NOT NULL UNIQUE REFERENCES pacientes(id_paciente),
    tipo_sangre           VARCHAR(5),
    alergias              TEXT,
    enfermedades_base     TEXT,
    medicamentos          TEXT,
    antecedentes          TEXT,
    emergencia_nombre     VARCHAR(100),
    emergencia_parentesco VARCHAR(40),
    emergencia_telefono   VARCHAR(20)
);
CREATE TABLE registros_clinicos (
    id_registro   SERIAL PRIMARY KEY,
    id_historia   INT NOT NULL REFERENCES historias_clinicas(id_historia),
    id_cita       INT REFERENCES citas(id_cita),
    id_medico     INT NOT NULL REFERENCES medicos(id_medico),
    fecha         TIMESTAMP NOT NULL DEFAULT now(),
    diagnostico   TEXT,
    tratamiento   TEXT,
    observaciones TEXT
);
CREATE TABLE accesos_historia (
    id_acceso     SERIAL PRIMARY KEY,
    id_historia   INT NOT NULL REFERENCES historias_clinicas(id_historia),
    id_medico     INT NOT NULL REFERENCES medicos(id_medico),
    id_cita       INT REFERENCES citas(id_cita),
    concedido_en  TIMESTAMP NOT NULL DEFAULT now(),
    UNIQUE (id_historia, id_medico)
);
CREATE TABLE resultados_laboratorio (
    id_resultado  SERIAL PRIMARY KEY,
    id_paciente   INT          NOT NULL REFERENCES pacientes(id_paciente),
    id_medico     INT REFERENCES medicos(id_medico),
    tipo_examen   VARCHAR(80)  NOT NULL,
    fecha         DATE         NOT NULL,
    archivo_url   VARCHAR(255) NOT NULL,
    subido_por    INT REFERENCES usuarios(id_usuario),
    creado_en     TIMESTAMP    NOT NULL DEFAULT now()
);
CREATE TABLE notificaciones (
    id_notificacion SERIAL PRIMARY KEY,
    id_usuario      INT          NOT NULL REFERENCES usuarios(id_usuario),
    id_cita         INT REFERENCES citas(id_cita),
    tipo            VARCHAR(30)  NOT NULL,
    mensaje         VARCHAR(250) NOT NULL,
    leida           BOOLEAN      NOT NULL DEFAULT FALSE,
    creada_en       TIMESTAMP    NOT NULL DEFAULT now()
);
CREATE TABLE bloqueos_agenda (
    id_bloqueo   SERIAL PRIMARY KEY,
    id_medico    INT REFERENCES medicos(id_medico),      -- NULL = toda la clínica (feriado)
    fecha_inicio DATE NOT NULL,
    fecha_fin    DATE NOT NULL,
    motivo       VARCHAR(150),
    CONSTRAINT ck_bloqueo_rango CHECK (fecha_fin >= fecha_inicio)
);
-- =====================================================================
-- Funciones, procedimientos y triggers
-- =====================================================================
-- 1) Cupos disponibles de un médico en una fecha
CREATE OR REPLACE FUNCTION fn_cupos_disponibles(p_medico INT, p_fecha DATE)
RETURNS TABLE (hora_inicio TIME, hora_fin TIME) AS $$
BEGIN
    RETURN QUERY
    SELECT s.ini::time, (s.ini + make_interval(mins => s.dur))::time
    FROM (
        SELECT g AS ini, h.duracion_min AS dur
        FROM horarios_atencion h,
             LATERAL generate_series(
                 p_fecha + h.hora_inicio,
                 p_fecha + h.hora_fin - make_interval(mins => h.duracion_min),
                 make_interval(mins => h.duracion_min)) AS g
        WHERE h.id_medico = p_medico
          AND h.estado
          AND h.dia_semana = EXTRACT(ISODOW FROM p_fecha)
    ) s
    WHERE s.ini > now()                                          -- no ofrecer horarios pasados
      AND NOT EXISTS (                                           -- cupo no ocupado por otra cita
            SELECT 1 FROM citas c
            WHERE c.id_medico = p_medico AND c.fecha = p_fecha AND c.estado <> 'Cancelada'
              AND tsrange(c.fecha + c.hora_inicio, c.fecha + c.hora_fin)
                  && tsrange(s.ini, s.ini + make_interval(mins => s.dur)))
      AND NOT EXISTS (                                           -- fecha bloqueada
            SELECT 1 FROM bloqueos_agenda b
            WHERE (b.id_medico = p_medico OR b.id_medico IS NULL)
              AND p_fecha BETWEEN b.fecha_inicio AND b.fecha_fin)
    ORDER BY 1;
END;
$$ LANGUAGE plpgsql STABLE;
-- 2) Validaciones de negocio al insertar una cita (RN03 a RN08)
CREATE OR REPLACE FUNCTION fn_validar_cita() RETURNS trigger AS $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pacientes WHERE id_paciente = NEW.id_paciente AND estado) THEN
        RAISE EXCEPTION 'El paciente no existe o está inactivo';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM medicos WHERE id_medico = NEW.id_medico AND estado) THEN
        RAISE EXCEPTION 'El médico no existe o está inactivo';
    END IF;
    IF NOT EXISTS (SELECT 1 FROM medico_especialidad
                   WHERE id_medico = NEW.id_medico AND id_especialidad = NEW.id_especialidad) THEN
        RAISE EXCEPTION 'El médico no atiende la especialidad indicada';
    END IF;
    IF NEW.fecha + NEW.hora_inicio < now() THEN
        RAISE EXCEPTION 'No se pueden registrar citas en fechas pasadas';
    END IF;
    IF NOT EXISTS (
        SELECT 1 FROM horarios_atencion h
        WHERE h.id_medico = NEW.id_medico AND h.estado
          AND h.dia_semana = EXTRACT(ISODOW FROM NEW.fecha)
          AND NEW.hora_inicio >= h.hora_inicio AND NEW.hora_fin <= h.hora_fin
          AND NEW.hora_fin - NEW.hora_inicio = make_interval(mins => h.duracion_min)) THEN
        RAISE EXCEPTION 'La cita está fuera del horario de atención del médico';
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER trg_validar_cita BEFORE INSERT ON citas
FOR EACH ROW EXECUTE FUNCTION fn_validar_cita();
-- 3) Bitácora de cambios de estado de las citas (trazabilidad, RF12)
-- Actualiza la fecha de última modificación
CREATE OR REPLACE FUNCTION fn_cita_actualizado() RETURNS trigger AS $$
BEGIN
    NEW.actualizado_en := now();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE OR REPLACE FUNCTION fn_cita_historial_after() RETURNS trigger AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        INSERT INTO cita_historial(id_cita, estado_anterior, estado_nuevo, id_usuario, observacion)
        VALUES (NEW.id_cita, NULL, NEW.estado, NEW.creado_por, 'Cita registrada');
    ELSIF NEW.estado IS DISTINCT FROM OLD.estado THEN
        INSERT INTO cita_historial(id_cita, estado_anterior, estado_nuevo, observacion)
        VALUES (NEW.id_cita, OLD.estado, NEW.estado, COALESCE(NEW.motivo_cancelacion, 'Cambio de estado'));
    END IF;
    RETURN NULL;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER trg_cita_actualizado BEFORE UPDATE ON citas
FOR EACH ROW EXECUTE FUNCTION fn_cita_actualizado();
CREATE TRIGGER trg_cita_historial AFTER INSERT OR UPDATE OF estado ON citas
FOR EACH ROW EXECUTE FUNCTION fn_cita_historial_after();
-- 4) Reglas de transición de estado (RF10)
CREATE OR REPLACE FUNCTION fn_validar_transicion() RETURNS trigger AS $$
BEGIN
    IF NEW.estado = OLD.estado THEN RETURN NEW; END IF;
    IF OLD.estado IN ('Cancelada','Atendida','Inasistencia') THEN
        RAISE EXCEPTION 'Una cita % no puede cambiar de estado', OLD.estado;
    END IF;
    IF OLD.estado = 'Programada' AND NEW.estado NOT IN ('Confirmada','Cancelada','Atendida','Inasistencia') THEN
        RAISE EXCEPTION 'Transición inválida de % a %', OLD.estado, NEW.estado;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;
CREATE TRIGGER trg_validar_transicion BEFORE UPDATE OF estado ON citas
FOR EACH ROW EXECUTE FUNCTION fn_validar_transicion();
-- 5) Función principal: programar una cita (una sola transacción, código generado)
CREATE OR REPLACE FUNCTION fn_programar_cita(
    p_paciente INT, p_medico INT, p_especialidad INT,
    p_fecha DATE, p_hora_inicio TIME, p_motivo VARCHAR, p_usuario INT)
RETURNS VARCHAR AS $$
DECLARE
    v_dur  SMALLINT;
    v_cod  VARCHAR;
BEGIN
    SELECT h.duracion_min INTO v_dur
    FROM horarios_atencion h
    WHERE h.id_medico = p_medico AND h.estado
      AND h.dia_semana = EXTRACT(ISODOW FROM p_fecha)
      AND p_hora_inicio >= h.hora_inicio AND p_hora_inicio < h.hora_fin
    LIMIT 1;
    IF v_dur IS NULL THEN
        RAISE EXCEPTION 'El médico no atiende en la fecha y hora indicadas';
    END IF;
    INSERT INTO citas(id_paciente, id_medico, id_especialidad, fecha, hora_inicio, hora_fin, motivo, creado_por)
    VALUES (p_paciente, p_medico, p_especialidad, p_fecha, p_hora_inicio,
            p_hora_inicio + make_interval(mins => v_dur), p_motivo, p_usuario)
    RETURNING codigo INTO v_cod;     -- si hay conflicto, la restricción EXCLUDE aborta la operación
    RETURN v_cod;
END;
$$ LANGUAGE plpgsql;
-- 6) Cancelar una cita conservando el registro
CREATE OR REPLACE FUNCTION fn_cancelar_cita(p_cita INT, p_motivo VARCHAR) RETURNS VOID AS $$
BEGIN
    UPDATE citas SET estado = 'Cancelada', motivo_cancelacion = p_motivo WHERE id_cita = p_cita;
    IF NOT FOUND THEN RAISE EXCEPTION 'La cita % no existe', p_cita; END IF;
END;
$$ LANGUAGE plpgsql;
