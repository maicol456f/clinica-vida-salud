# Clínica Vida Salud — Sistema Web de Gestión de Citas Médicas (Ciclo 1)

Implementa los casos de uso CU-01 a CU-12 del documento *Avance 1*: sesión y roles, recuperación de contraseña,
usuarios, especialidades, médicos, horarios, pacientes, disponibilidad, programación, reprogramación y cancelación de citas.

```
clinica-vida-salud/
├── backend/            API REST en PHP 8.1+ (PDO + PostgreSQL)
│   ├── public/index.php      router / punto de entrada
│   ├── src/                  núcleo (BD, seguridad, validación) y controladores
│   ├── sql/schema.sql        script DDL del documento (tablas, EXCLUDE anti-traslape, funciones y triggers)
│   ├── scripts/setup.php     crea el esquema y los datos iniciales (idempotente)
│   ├── Dockerfile            para Render
│   └── .env.example
├── frontend/           HTML5 + CSS3 + JavaScript (sin build)
│   ├── index.html · css/ · js/config.js (URL del backend) · js/api.js · js/app.js
├── docker-compose.yml  PostgreSQL local opcional
└── render.yaml         Blueprint opcional para Render
```

## Ejecutar en local
1. Requisitos: PHP 8.1+ con `pdo_pgsql` y `mbstring`, y PostgreSQL 14+ (o `docker compose up -d` para la base).
2. Crear la base: `createdb -U postgres vida_salud`
3. Backend:
   ```
   cd backend
   cp .env.example .env        # (Windows: copy .env.example .env) y ajuste DB_PASSWORD
   php scripts/setup.php
   php -S localhost:8000 -t public public/index.php
   ```
4. Frontend (otra terminal): `cd frontend && php -S localhost:5173`  →  http://localhost:5173

## Usuarios de demostración (cambie las claves en producción)
| Rol | Usuario | Contraseña |
|---|---|---|
| Administrador | admin | Admin123! |
| Recepcionista | recepcion | Recep123! |
| Médico | crojas (también lpereira, msalvatierra, avaca) | Medico123! |
| Paciente | paciente | Paciente123! |

Defina `SEED_DEMO=0` para cargar sólo el administrador (clave en `ADMIN_PASSWORD`).

## Seguridad implementada
Contraseñas con `password_hash`, token firmado con expiración, consultas parametrizadas (PDO), validación en cliente y servidor,
escape de salida contra XSS, control de acceso por rol, errores sin detalles internos, CORS restringido (`ALLOWED_ORIGINS`),
y prevención de citas superpuestas con validación en servidor + transacción + restricción `EXCLUDE` de PostgreSQL.
