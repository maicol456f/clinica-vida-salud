// URL del backend.
// - En local se usa http://localhost:8000
// - En Render: pegue aquí la URL pública de su servicio backend (sin "/" final).
window.APP_CONFIG = {
  PROD_API_URL: "https://vida-salud-api.onrender.com",
  API_URL: ["localhost", "127.0.0.1"].includes(location.hostname)
    ? "http://localhost:8000"
    : "https://vida-salud-api.onrender.com"
};
