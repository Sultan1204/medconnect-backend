# Temporary deployment on Render (backend)

1. Render → New → **Blueprint** → pick this repo. It reads `render.yaml` (Docker web service + free Postgres).
2. When asked, set `APP_URL` to the service URL (e.g. `https://medconnect-backend.onrender.com`) and
   `CORS_ALLOWED_ORIGINS` to the **frontend** URL (no trailing slash).
3. Wait for the deploy; `GET /up` should return 200 and `GET /api/v1/specialties` the specialty list.

Notes
- One container runs nginx, php-fpm, the queue worker and the scheduler (free plans have no background workers).
- `APP_ENV=staging`: the `log` SMS driver refuses to run in production. OTP codes appear in the service **Logs**
  (`[dev-sms]`). This is a demo only - never use real patient data.
- `SEED_DEMO_DATA=true` seeds synthetic accounts (password `Password123`, doctor/admin TOTP secret
  `JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP`). They are public knowledge: delete the service when testing ends.
- Free Postgres instances expire after a limited period; free web services sleep when idle (first request is slow).
- The Docker build has not been run in the authoring environment (no Docker daemon); report build errors and they will be fixed.
