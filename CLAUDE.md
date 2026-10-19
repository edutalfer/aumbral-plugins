# A Umbral — plugins de la app interna

Contexto para Claude Code. Léelo entero antes de tocar nada.

aumbral.com es la plataforma de entrenamiento ciclista de Eduardo Talavera (~290 suscripciones
activas). WordPress + Elementor Pro + WooCommerce Subscriptions + LearnDash, en Hostinger
(compartido, LiteSpeed, CloudLinux). Este repo contiene los plugins propios `aumbral-*`.

**Trabajamos directamente sobre producción. No hay staging.**

---

## 1. Personas y preferencias

- **Eduardo** (usuario WP 1, edutalfer@gmail.com): dueño. Respuestas en español, concisas.
  Prefiere que ejecutes y decidas cuando la respuesta es clara, en vez de presentar opciones.
  Tablas para datos. Trabaja en Mac.
- **Julia** (usuario WP 368, `julia.talavera`, jultalav@gmail.com): lleva **toda la operativa**
  (planes, pagos, bajas). Conoce la jerga de la app; no hace falta simplificar textos ni guías.
- Decisiones ya tomadas (no volver a proponer): sin pestaña «Hoy», sin guía para Julia, la
  jerga se queda, Pagos está bien como está, fuera la pestaña «Vencen» de Planes.

---

## 2. Acceso

```
SSH: host 145.14.156.188 · puerto 65002 · usuario u941038388 · clave ~/.ssh/id_ed25519
WP:  /home/u941038388/domains/aumbral.com/public_html
```

| Repo | Contenido | Deploy key en el servidor |
|---|---|---|
| `edutalfer/aumbral-plugins` | este repo | `~/.ssh/github_plugins` |
| `edutalfer/aumbral-child` | tema hijo | `~/.ssh/github_aumbral` |

En el servidor hay una copia de trabajo en `~/repos/aumbral-plugins`. GitHub no permite
compartir deploy key entre repos: cada uno la suya.

---

## 3. La app interna (`aumbral.com/app/`)

PWA privada. Login de WordPress + capacidad `manage_woocommerce`. Cada sección es un plugin
que se registra con el filtro `aumbral_app_modulos` (claves: nav, titulo, orden, cap, badge,
render(array $ctx)). Desactivar uno no rompe los demás. Los módulos se hablan entre sí solo
con `class_exists()`.

| Plugin | Contenido |
|---|---|
| `aumbral-app` | Contenedor: ruta `/app/`, PWA (manifiesto, SW, icono GD), cabecera, nav, CSS común, vista móvil/escritorio por usuario, `resumen.php` (resumen diario), remitente de correo, envío a clientes (`admin_post_aumbral_enviar`), `bin/app-check` y `checks.php` |
| `aumbral-pagos` | `aumbral-pagos.php`: motor de fallos de renovación, veredictos, `clientes()`, `bajas()`, BCLB. `app.php`: pantalla, pulso, insistir, bajas, BCLB, bloque del resumen |
| `aumbral-planes` | Solicitudes de plan desde formularios Elementor, semanas de inicio, borradores de respuesta, buscador. Tabla `wp_aumbral_planes` |
| `aumbral-tareas` | Tablero compartido sobre `wp_aumbral_tasks` (misma tabla que `/tareas/` del tema) |

### Otros plugins propios en producción (de otros chats, **aún sin versionar** — ver §8)

| Plugin | Qué hace (según su cabecera) |
|---|---|
| `aumbral-bajas` | Al cancelar desde «Mi cuenta»: correo de agradecimiento y pregunta el motivo. Pagos muestra los motivos |
| `aumbral-correo` | Envío de correos de WP por Amazon SES con respaldo en Hostinger; avisos al equipo |
| `aumbral-dudas` | Resuelve con Claude dudas de quien piensa contratar, desde una base de conocimiento |
| `aumbral-ev` | Analítica propia (navegación, tienda, área de miembros, Lab) en tablas propias |
| `aumbral-news` | Newsletter propia (`/app/news/`): doble opt-in, bajas, tracking, editor de campañas |
| `aumbral-opiniones` | Formulario de opinión trimestral en «Mi cuenta» |
| `aumbral-telegram` | Comunidad de Telegram ligada a la suscripción, vía bot |

---

## 4. Reglas de negocio

### Cruce de clientes — `AUP_Pagos::clientes()`
- Indexa cada suscripción por **correo** y por **usuario WP** (`uid:N`); manda el mejor
  estado. La gente se re-da de alta con otro correo de facturación.
- **Sin caché persistente.** Un transient de 5 min sirvió datos de días y dio avisos falsos.

### Planes
- Fuente: `wp_e_submissions` de Elementor → `wp_aumbral_planes`. Campos reconocidos **por
  contenido**, no por ID de campo.
- 2 semanas de inicio si en *cualquier* formulario suyo declara que es su primer plan.
- Calendario lunes-domingo: solicitud lun-mié arranca esa semana; jue-dom, la siguiente.
- Fichas en inicio **vuelven a Pendientes el viernes previo** al lunes del paso
  (`AVISO_DIAS = 3`), etiqueta «Viene de las 2 semanas». El resumen usa la misma ventana.
- Varias solicitudes de una persona: la ficha dice cuál es la última, con hora. Botón
  «Es la misma persona» une una nueva con el plan en curso (`plan_destino`).
- Aviso rojo si la suscripción no está activa.

### Pagos
- Veredictos: CONTACTAR, REVISAR, ESPERAR, ALTA, PERDIDA, DUPLICADA, SUSTITUIDA, RESUELTO.
- **Insistir**: un caso «hecho» vuelve a los 5 días si sigue sin pagar; se cierra solo al pagar.
- Pulso en la tarjeta: activas, de salida, altas y bajas del mes.

### Bajas
- La fecha que importa es `_schedule_end` (fin del periodo pagado), en **UTC** → convertir a local.
- **No es baja** quien tiene otra suscripción activa (por correo o usuario). Si no, salían
  como «quitar de TrainingPeaks» clientes que pagan.

### Club BCLB (cupones `clubbclb`, `clubbclb2`)
- Pertenencia por **suscripción**, no por cupón del pedido (las renovaciones no siempre lo copian).
- Solo cuotas **no anuales**.
- Cobrado − `_stripe_fee` real → 50% → de ahí base + IVA 21%. Sumar con decimales y
  **redondear una sola vez al final**. Validado por el gestor.
- Correo el día 1 de ene/abr/jul/oct, 09:00, a `info@bicilab.es` (se queda así).

---

## 5. Correos

| Correo | A quién | Cuándo |
|---|---|---|
| Resumen diario completo | Julia | 07:30, solo si hay algo |
| Resumen de tareas | Eduardo | 07:30, solo sus tareas con fecha |
| Alerta de pago fallido | Julia (`aup_pagos_settings['email']`) | 10 min tras el fallo |
| Liquidación BCLB | info@bicilab.es | Día 1 tras el trimestre |
| Respuestas a clientes | cliente | Botón Responder: sale como info@aumbral.com, Bcc a info@, cabecera `X-Aumbral-Operador` |

- Quién recibe el resumen completo: `AUmbral_Resumen::COMPLETO` (`array( 368 )`).
- Apagados por estar cubiertos en la app: avisos admin de WooCommerce `new_order`,
  `failed_order`, `cancelled_order`, `cancelled_subscription`; aviso de tareas del tema y
  resumen semanal de pagos (interceptados por asunto); aviso de formularios Elementor
  (interruptor en Planes, encendido).
- **El prefijo `[www.aumbral.com]` lo llevan también correos a clientes. Nunca filtrar por él.**
- `wp-mail-logging` registra el *intento*, aunque luego se intercepte.

---

## 6. Normas de trabajo (aprendidas a golpes)

1. **Backup antes de tocar** en `~/backups/` con fecha. BD: `mysqldump` directo
   (`wp db export` falla en silencio).
2. WP-CLI siempre con `--allow-root`. Elementor necesita `--user=1`.
3. **Tras cada cambio: `app-check --purga`** (purga `pkill -f lsphp` + LiteSpeed y ejecuta
   sintaxis, rutas y todas las vistas por dentro). Nunca dar algo por bueno sin pasarlo.
   Si falla en rutas distintas cada vez y PHP muere por señal 9, es saturación del servidor
   (load 15+): repetir antes de tocar código.
4. **Parches con anclas únicas.** Un parche con dos sustituciones sobre el mismo fichero
   provocó una recursión infinita y un 503.
5. Comandos con red o que puedan pedir entrada: `timeout` y `BatchMode=yes`. Un `git` que
   esperaba confirmación colgó la sesión.
6. Probar correos sin enviarlos: filtro `pre_wp_mail` prioridad 1 que capture y devuelva `true`.
7. Probar con datos reales y **verificar también en negativo** (que un aviso siga saltando
   cuando toca, que una baja real siga en la lista).
8. Pruebas que modifiquen datos reales: modificar, comprobar y **restaurar**, verificando
   la restauración.
9. Subir a GitHub: `plugins-commit "mensaje"` en el servidor (rsync producción → repo,
   commit y push). Sentido siempre producción → Git.

---

## 7. Pendiente conocido

- `[ENLACE DEL CUESTIONARIO]` en los borradores de respuesta: esa página no existe.
- Cobros de 17,90 € a gente del club: no se aplicó el descuento de 4 €.
- `alen.apx@gmail.com` volvió en 03/2026 sin cupón; no cuenta para el club.
- Club BCLB: 107 suscriptores en T1 2026 → 60 en T3.
- `~/backups/` ocupa ~3 GB en hosting compartido: rotar.
- Carga del servidor alta (Action Scheduler, AutomateWoo, Jetpack, LiteSpeed) y tareas de
  terceros fallando en bucle (`fetch_patterns`, `cartflows_update_knowledge_base_data`).
- Los 8 plugins del Lab (calculadoras) sin versionar.

## 8. Estado del repo a 19/10/2026

- Los 7 plugins de la segunda tabla de §3 no están en ningún repo.
- `~/bin/plugins-commit` solo sincroniza `aumbral-app aumbral-pagos aumbral-planes aumbral-tareas`.
