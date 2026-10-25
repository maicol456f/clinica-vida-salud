-- =====================================================================
-- Migración idempotente: bitácora general + bloqueo por intentos fallidos
-- Se ejecuta en cada arranque desde scripts/setup.php (se puede repetir sin riesgo).
-- =====================================================================

-- 1) Bloqueo de cuentas tras N intentos fallidos consecutivos
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS intentos_fallidos INT       NOT NULL DEFAULT 0;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS bloqueado         BOOLEAN   NOT NULL DEFAULT FALSE;
ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS bloqueado_en      TIMESTAMP;

-- 2) Bitácora general del sistema (auditoría)
CREATE TABLE IF NOT EXISTS bitacora (
    id_bitacora  BIGSERIAL    PRIMARY KEY,
    fecha_hora   TIMESTAMP    NOT NULL DEFAULT now(),
    id_usuario   INT,                              -- sin FK: el registro se conserva aunque cambie el usuario
    usuario      VARCHAR(120),                     -- nombre de usuario (o lo que se digitó en un login fallido)
    rol          VARCHAR(30),
    modulo       VARCHAR(30)  NOT NULL,            -- Sesión, Usuarios, Pacientes, Citas...
    accion       VARCHAR(40)  NOT NULL,            -- LOGIN_OK, LOGIN_FALLIDO, CITA_CREADA...
    detalle      VARCHAR(500),
    resultado    VARCHAR(10)  NOT NULL DEFAULT 'OK' CHECK (resultado IN ('OK', 'FALLIDO')),
    ip           VARCHAR(64)
);
CREATE INDEX IF NOT EXISTS idx_bitacora_fecha   ON bitacora(fecha_hora DESC);
CREATE INDEX IF NOT EXISTS idx_bitacora_usuario ON bitacora(id_usuario);
CREATE INDEX IF NOT EXISTS idx_bitacora_modulo  ON bitacora(modulo, accion);

-- 3) La bitácora es de sólo inserción: nadie puede editar ni borrar registros
CREATE OR REPLACE FUNCTION fn_bitacora_inmutable() RETURNS trigger AS $$
BEGIN
    RAISE EXCEPTION 'La bitácora es de sólo lectura: no se puede modificar ni eliminar';
END;
$$ LANGUAGE plpgsql;
DROP TRIGGER IF EXISTS trg_bitacora_inmutable ON bitacora;
CREATE TRIGGER trg_bitacora_inmutable BEFORE UPDATE OR DELETE ON bitacora
FOR EACH ROW EXECUTE FUNCTION fn_bitacora_inmutable();
