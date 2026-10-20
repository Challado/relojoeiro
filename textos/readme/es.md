<!-- origem: 612d2bb943855be9901e0a239e806a15f3cebe76 -->
<!-- parte 1 -->
# ⌚ Relojoeiro — Relógios 2

🌐 [Português](README.md) · [English](README.en.md) · [中文](README.zh-CN.md) · [हिन्दी](README.hi.md) · **Español** · [Français](README.fr.md)

> **La rotación inteligente de una colección de relojes.** Cada día dice qué reloj va a la muñeca, recuerda dar cuerda,
> cargar, poner al sol y cambiar la pila, y hace que se use toda la colección, y no solo el favorito. Varias personas usan
> el mismo sistema, cada una con sus colecciones, y comparten lo que quieran.

**[▶ Pruébelo ahora, sin instalar nada](https://servidor2.lucas.inf.br/relojoeiro/cadastro.php)**: cree una cuenta de demostración en el servidor del proyecto
(vale 30 días, ya con una colección lista para que registre sus relojes).

![PHP 8.1+](https://img.shields.io/badge/PHP-8.1%2B-777bb4?logo=php&logoColor=white)
![SQLite, MySQL/MariaDB, PostgreSQL o Firebird](https://img.shields.io/badge/base%20de%20datos-SQLite%20%7C%20MySQL%20%7C%20PostgreSQL%20%7C%20Firebird-336791)
![sin dependencias](https://img.shields.io/badge/dependencias-ninguna-2ea44f)
![API REST](https://img.shields.io/badge/API-REST%20%2B%20JSON%2FXML-0366d6)
[![licencia](https://img.shields.io/badge/licencia-c%C3%B3digo%20fuente%20disponible-orange)](#licença)

![La página Hoy: el reloj del día, qué hacer hoy, la escala de los próximos días y el panel de un reloj](docs/telas/hoje.png)

<sub>La página **Hoy** con una colección de demostración de 8 relojes (modelos comunes, seis semanas de uso registradas por la
API): el del día (el Casio, en la muñeca desde las 7 h), qué hacer hoy, la escala inteligente de los próximos días y, a la
derecha, el panel del Tissot, abierto al lado (en la Configuración puede abrirse flotante, sobre la página).</sub>

| | |
|---|---|
| **[Por qué existe](#por-que-isso-existe)** · [La idea central](#a-ideia-central-o-sistema-não-sabe-nada-de-relógios) · [Cómo funciona](#como-funciona) · [Un día con él](#um-dia-com-o-relógios-2) | **[Pruébelo en 1 minuto](#experimente-em-1-minuto)** · [Las pantallas](#as-telas) · [La API](#a-api-uma-porta-só-para-tudo) · [Instalación](#instalação) |
| [Varias personas](#várias-pessoas-contas-coleções-e-acesso) ·[Preguntas frecuentes](#perguntas-frequentes) · [Cómo es por dentro](#como-é-por-dentro) | [Cómo contribuir](#como-contribuir) · [Licencia](#licença) |

---

## Por qué existe esto {#por-que-isso-existe}

Quien colecciona relojes conoce la escena:

- **El favorito va a la muñeca todos los días**, y aquel que compró con tanto cariño lleva dos meses en el cajón.
- **El automático se para** porque nadie lo usó el fin de semana, y ahí va usted a poner otra vez la hora, la fecha y la fase
  de la luna.
- **El solar se descarga en la oscuridad** de la caja, sin que usted se dé cuenta.
- **La pila se acaba sin aviso**, justo en el reloj que quería usar hoy.
- **El smartwatch amanece sin batería** el día en que le tocaba.
- **La revisión del mecánico** ("cada 5 años") y **la garantía** ("¿vence en marzo?") quedan en la memoria, es decir, en
  ninguna parte.

Una hoja de cálculo resuelve la mitad: recuerda, pero no decide. **Relógios 2** decide. Sabe cuánto tiempo lleva parado cada
reloj, cuánta carga tiene ahora, cuánto le gusta y cuánto se ha usado en relación con el resto. Con eso arma el plan de los
próximos días, o de los próximos dos años, con los recordatorios ya en su sitio.

Por la mañana llega un mensaje por Telegram: *"Hoy: Seiko 5. Dar cuerda al Orient (la reserva se acaba a las 15 h)."*
Por la noche llega otro: *"Mañana: Xiaomi Band. Cargar antes de dormir: llega al 20% mañana a las 14 h."*
Y en Google Calendar están el cambio de pila del Casio en noviembre y la revisión del Tissot en 2027.

<!-- parte 2 -->
## Pruébelo en 1 minuto {#experimente-em-1-minuto}

**Sin instalar:** cree una cuenta de demostración en
[servidor2.lucas.inf.br/relojoeiro](https://servidor2.lucas.inf.br/relojoeiro/cadastro.php) (vale 30 días; la cuenta nace con una
colección y el conjunto inicial, y solo usted ve lo que registre en ella).

**En su máquina:** solo necesita PHP 8.1+ con la extensión `sqlite3`: sin servidor web y sin servidor de base de datos.

```sh
git clone https://github.com/Challado/relojoeiro.git && cd relojoeiro
cp config.exemplo.php config.php
```

En el `config.php`, cambie dos líneas para usar SQLite (el archivo de la base queda al lado de la carpeta):

```php
define("DB_TIPO", "sqlite");
define("DB_ARQUIVO", __DIR__ . "/../relogios.sqlite");
```

```sh
php instalar.php --lingua=es  # la estructura y el conjunto inicial (en español)
php criar_usuario.php usted   # pide la contraseña
php -S localhost:8000         # y abra http://localhost:8000
```

Registre algunos relojes en **Hoy → Nuevo reloj**. Para armar la escala sin esperar al cron, ejecute `php cron.php --forcar`.

> El servidor integrado de PHP (`php -S`) es solo para probar: entrega cualquier archivo de la carpeta, incluso el
> `config.php`. Para usarlo de verdad, siga la [Instalación](#instalação).

---

<!-- parte 3 -->
## La API: una sola puerta para todo {#a-api-uma-porta-só-para-tudo}

El `api.php` es el **único back-end**. Las páginas son solo la pantalla: el JavaScript de cada una llama a la API y arma el
HTML, sin hacer cuentas (los resúmenes, los porcentajes, los totales y las situaciones, como el resumen del Plan y la
situación del cron, vienen listos de la API; en la pantalla queda solo lo que es suyo: filtrar, ordenar y sumar lo que los
filtros de la tabla dejan a la vista). Por eso, **todo lo que hace la pantalla, también lo hace un script**: automatizaciones,
atajos en el móvil, integración con Home Assistant, un widget.

La API hace también lo que viene antes y alrededor de las pantallas: **instala la base de datos** (`recurso=instalacao`),
**crea el primer usuario** (`recurso=usuarios`, con el token), **importa el sistema anterior** (`recurso=importacao`), el
**registro abierto** (`recurso=cadastro`, sin login), las **cuentas** (`conta`, `colecoes`, `acessos`, `convites`, `pedidos`,
`aprovacoes`) y hace el **login de las páginas** (`recurso=entrar`: cuando el login se cae con la página ya abierta, la API pide el usuario y la
contraseña y lo devuelve a la página; al abrir, la propia página ya los pide, antes de enviar nada). Los
scripts de línea de comandos (`instalar.php`, `criar_usuario.php`, `importar.php`) son atajos: llaman a las mismas
operaciones de la API.

Fuera de la API solo queda el **cron** (`cron.php`, el reloj del sistema: monta el plan, abre y cierra la sesión del día,
envía los mensajes, sincroniza los calendarios, dispara los eventos y cuida de las copias de los documentos), que usa las
mismas funciones que usa la API; lo que se puede pedir en el momento tiene acción en la API (el `testar` de los destinos, el
`sincronizar` de los calendarios, el `resortear`...), y lo que hizo aparece en `recurso=cron`.

**Autenticación:** el login del sitio (HTTP Basic), un **token personal** (creado en **Mi cuenta**, vale como su login), la clave de un **aparato recordado** (la cookie de la aplicación en el celular) o el
token del servidor (el `API_TOKEN`, vale como el master), en la cabecera `X-Api-Token` o en `?token=...`. **La colección:**
`colecao=<id>` (sin él, la del selector del menú, si no la primera suya); una que usted no ve responde 404. **Eliminar** pide
`confirmar=1`: sin él, la respuesta (409) dice lo que saldría junto y no se borra nada.
**Formato:** JSON por defecto, o XML con `formato=xml`. **Idioma:** `lingua=es` (o la cabecera `Accept-Language`): los
mensajes, la ayuda y la reconstrucción salen en ese idioma.

```sh
# todo lo que está en el sistema, sin las fotos
curl -u usuario:contraseña "http://servidor/relojoeiro/api.php?foto=nao"

# lo que muestra la página Hoy
curl -H "X-Api-Token: $TOKEN" "http://servidor/relojoeiro/api.php?recurso=hoje"

# los relojes que se acaban en las próximas 24 horas, solo el nombre y cuándo
curl -u usuario:contraseña -g "http://servidor/relojoeiro/api.php?recurso=autonomia&f[relogios][acaba_em_segundos][ate]=86400&mostrar[relogios]=nome,acaba_em_datacomtz"

# registrar una lectura de carga (y medir el consumo); estado=uso o repouso: cómo estaba a la hora de la lectura (opcional)
curl -u usuario:contraseña -d recurso=lancamento -d acao=lancar -d relogio_id=10 -d tipo=carga -d valor=68 -d estado=repouso http://servidor/relojoeiro/api.php

# poner al sol ahora... y quitarlo después
curl -u usuario:contraseña -d recurso=lancamento -d acao=iniciar  -d relogio_id=4 -d tipo=sol http://servidor/relojoeiro/api.php
curl -u usuario:contraseña -d recurso=lancamento -d acao=encerrar -d relogio_id=4 -d tipo=sol http://servidor/relojoeiro/api.php

# probar una fórmula en dos relojes, sin guardar
curl -u usuario:contraseña -G "http://servidor/relojoeiro/api.php" -d recurso=calcular --data-urlencode "expressao=energia * 2" -d relogio=10,12
```

**Consultas** (`GET`, con `recurso=`):

| Recurso | Devuelve |
|---|---|
| *(ninguno)* | todo lo guardado: el árbol, los campos, los tipos de registro, las fórmulas, los avisos, los relojes (con los valores, las fórmulas calculadas, los avisos, los registros, la previsión, la foto y los datos de los documentos), las categorías de los documentos, los modos, el plan, la configuración, los eventos, el calendario, los criterios, el cron, los usuarios (con el hash de la contraseña: la contraseña no existe en el sistema) y las migraciones. `foto=nao` deja fuera las fotos. |
| `hoje` | lo que muestra la página Hoy: el reloj del día, los avisos, los próximos días, los modos, la tabla de la colección y sus totales (cuántos relojes, disponibles, en uso y el valor pagado) |
| `ficha` | lo que muestra el panel de un reloj (`relogio=<id>`; sin él, solo la ficha de un reloj nuevo): ahora, carga, nota, previsión, consumo de la batería, cuántos documentos en cada categoría, marcas recientes, ficha |
| `proximos` | los Próximos días de la página Hoy, una página (`pagina=`, `por_pagina=`; sin él, el "Mostrar hasta" de la Configuración), con los días libres en medio |
| `rodizio_manual` | la Rotación manual: los días de un período (`de=`, `ate=`) de 7 en 7 (`pagina=`), cada uno con lo que el plan tiene ahora y la elección de partida del selector (el reloj elegido a mano, `livre` o `auto`: Decide por mí), los relojes disponibles y el criterio del Decide por mí |
| `plano` | el plan guardado, día a día, con el motivo de cada elección, el resumen del período como lo muestra la página Plan (cuántos días tiene cada reloj, el porcentaje, el próximo día, cuántos a mano, quién se quedó sin día) y el `dia_a_dia` una página cada vez (`pagina=`, `por_pagina=`, `relogio=`). `de=` y `ate=` (AAAA-MM-DD; sin ellos, el plan entero) o `dias=N` (los próximos N días; `dias=todos`, de hoy hasta el final) |
| `avisos` | los avisos de todos los relojes, del más urgente al más lejano |
| `previsao` | la previsión de la batería de los relojes con lectura de carga (`relogio=` un id o varios separados por comas; sin él, todos) |
| `autonomia` | cuánto dura cada reloj, en segundos, resumido para sistemas externos (`relogio=` un id o varios; sin él, todos) |
| `historico` | la línea de tiempo de un reloj, con el tiempo en cada estado. `relogio=` (un id o varios; `relogio_id` también vale; sin él, todos), `de=` y `ate=` (fecha, o fecha y hora: entra el tramo que cruza el período), `estado=` (`rodizio`, `pulso`, `repouso`, `marca` o un tipo de sesión como `winder` y `sol`; uno o varios; `linha_estado` también vale), `ordem=` (`desc`, el predeterminado, o `asc`), `limite=` (predeterminado 100, máximo 1000) y `pagina=` |
| `criterios` | los conjuntos de criterios y la nota de cada reloj con la cuenta |
| `eventos`, `destinos`, `agendas` | los eventos personalizados; los destinos de los mensajes, con lo que recibe cada uno y la vista previa de hoy; los calendarios, con lo que tiene que estar en cada uno |
| `ics` | el calendario de un calendario de tipo enlace ICS (sin login, por la clave del enlace) |
| `config`, `cron` | la configuración de la colección (con el resumen de los destinos y de los calendarios y la situación del cron: nunca se ejecutó, detenido o funcionando); las ejecuciones del cron, con el resumen del período. En `cron`: `de=` y `ate=` (fecha, o fecha y hora), `situacao=` (`atividade`, por defecto; `erro`, `nada` o `todas`), `busca=` (texto en el registro), `limite=` (por defecto 100, máximo 1000) y `pagina=`; cada uno vale también con `cron_` delante (`cron_de`...) |
| `arvore`, `cadastros` | los grupos; todo lo que muestra la página Definiciones |
| `calcular` | el resultado de una fórmula (`expressao=`) en los relojes (`relogio=` un id o varios; sin él, todos), sin guardar, con las partes de la cuenta; fórmula mal escrita: 400 con los errores |
| `foto` | la foto de un reloj (`relogio=<id>`): la imagen, no JSON |
| `documentos` | los documentos de un reloj (`relogio=<id>`; sin él, de todos), con las categorías, el resumen de la NF-e de cada XML, la carpeta y el límite |
| `documento` | el archivo de un documento (`id=<id>`): para mostrar (imagen, video, audio, PDF, texto) o para descargar (el resto, o con `baixar=1`); `mini=1` es la miniatura de la foto. Atiende trozos (Range), para que el video avance |
| `usuarios`, `migracoes` | el master: el panel de las cuentas, cada una con lo que tiene y el hash de la contraseña (para migrar con la misma contraseña: vea [Los usuarios y las contraseñas](#os-usuários-e-as-senhas)); los demás, solo su propia cuenta; las migraciones que faltan aplicar |
| `conta`, `colecoes`, `aprovacoes` | Mi cuenta (las colecciones, los árboles, las solicitudes, los tokens, los aparatos recordados, los aparatos del Web Push; `parte=menu`: solo lo del menú); las colecciones y los árboles; la página Aprobaciones |
| `manifest`, `sw` | la aplicación en el celular, sin login: el manifiesto (JSON; con la cookie del aparato recordado, el `start_url` ya entra) y el *service worker* (JavaScript). Vea [La aplicación en el celular](#o-aplicativo-no-celular) |
| `cadastro` | el registro abierto, sin login: si está abierto y la invitación (`convite=<token>`); ninguna lista de cuentas ni de colecciones (la solicitud de acceso es por el código que pasó el dueño) |
| `instalacao` | la situación de la instalación: la base, si está vacía, instalada y al día, cuántos usuarios y el próximo paso. Responde también con la base vacía, con el token |
| `backup` | la copia de seguridad de la base: si está activada, si la carpeta sirve, cuántas se guardan, la última y las copias de la carpeta; `arquivo=` descarga una |
| `importacao` | lo que usa la importación del sistema anterior: el servidor de la base antigua (sin la contraseña), si PHP tiene MySQL y si esta base ya tiene relojes |
| `entrar` | el login de las páginas: sin login, 401 (el navegador pide el usuario y la contraseña); con login, vuelve a la página de `volta=` (302) o dice quién entró |
| `ajuda` | la documentación entera: cada consulta, cada escritura, cada campo de cada respuesta y las funciones del motor |
| `manual` (o `ajuda&parte=manual`) | el texto de la página **Ayuda** (las secciones de este README para quien usa: de la idea central a las preguntas frecuentes), en markdown, entero y sección por sección, con el título, el nivel y el ancla de cada una, y ya en HTML con el índice (la página Ayuda se arma con él) |
| `reconstrucao` (o `ajuda&parte=reconstrucao`) | el guion para **reescribir el sistema entero desde cero** (vea [Reescribir el sistema desde cero](#reescrever-o-sistema-do-zero)) |
| `codigo` (o `ajuda&parte=codigo`) | **el código del sistema tal como está ahora**, leído del disco en el momento: cada archivo con la capa, qué es, el lenguaje, el tamaño, las líneas, el SHA-256 y la fecha, y lo que queda fuera a propósito (`nao_mostrados`). `arquivo=<nombre>` trae el contenido (la imagen en base64; en una página, también el HTML que recibe el navegador); `cru=1` trae el archivo puro, sin JSON, para leer en el navegador; `conteudo=1` los trae todos (vea [Reescribir el sistema desde cero](#reescrever-o-sistema-do-zero)) |

**Escrituras** (`POST`, con `recurso=` y `acao=`), todas con las mismas validaciones y mensajes de la pantalla:

| Recurso | Acciones |
|---|---|
| `relogio` | `salvar` (crear o cambiar), `excluir` (con los documentos), `foto`, `remover_foto` |
| `documentos` | `enviar` (uno o varios archivos, con la categoría, el título, la fecha, la descripción y, en una foto, la miniatura), `alterar`, `excluir` |
| `documento_categorias` | `nova`, `alterar`, `excluir` (solo sin documentos) |
| `lancamento` | `lancar` (cuerda, carga, pila...), `iniciar` y `encerrar` (poner y quitar de la muñeca, del winder, del sol, del cargador), `alternar` (cierra la sesión si está abierta, la abre si está cerrada: un solo botón en el móvil), `periodo` (un período que ya pasó; en la muñeca del reloj del día es uso de la rotación, y los tramos del mismo tipo con los que se toca, como el Poner que quedó abierto, se vuelven uno), `alterar`, `excluir`, `medicao` (una medición del consumo entra o sale de la cuenta: `id`, `usada` 1 o 0). En todas, `relogio_id=hoje` es el reloj del día |
| `rodizio` | `usando` (estoy usando este hoy), `trocar_dia` (el reloj de un día), `periodo` (los relojes de un período, en orden: `de`, `ate`, `relogio_ids[]`, `dias_cada`), `periodo_sortear` (borra los días del período y el modo los vuelve a montar; solo `de`: hasta el final del plan; solo `ate`: desde mañana hasta él), `periodo_livre` (borra y deja los días libres, sin sorteo; las mismas fechas), `manual` (la Rotación manual: `de`, `ate`, `dias[AAAA-MM-DD]` = el id del reloj, `auto` o `livre`), `apagar_tudo` (toda la programación de mañana en adelante se va; `hoje=1`: de hoy en adelante; el modo arma el plan de nuevo), `resortear`, `resortear_hoje`, `proxima_semana`, `modo` (cambiar el modo) |
| `modos` | `salvar`, `ativar`, `excluir` |
| `arvore` | `novo`, `renomear`, `mover`, `ordem`, `excluir` (grupos) y `relogios` (el grupo de cada reloj) |
| `campos`, `avisos`, `lancamento_tipos` | `novo`, `alterar`, `excluir` (y `ordem` en los campos) |
| `formulas` | `nova`, `alterar`, `excluir` |
| `criterios` | `conjunto_criar`, `conjunto_excluir`, `param_novo`, `param_excluir`, `param_pesos`, `sub_novo`, `sub_excluir`, `sub_medida`, `sub_mover`, `sub_pesos`, `faixas`, `ordem`, `restaurar` |
| `config` | `salvar`, `evento_salvar`, `evento_excluir` |
| `destinos` | `salvar` (webhook, correo o Web Push, con lo que recibe), `testar` (`qual=`: texto, mañana o noche), `excluir` |
| `agendas` | `salvar` (Google, CalDAV, Outlook o ICS), `sincronizar`, `teste_criar`, `teste_remover`, `novo_link` (ICS), `outlook_conectar`, `excluir` |
| `usuarios` | `salvar` (con la `senha`, o con el `senha_hash` de otro sistema; el primer usuario, con el token, es el master) y, solo el master: `alterar`, `oficializar`, `prorrogar`, `voltar_demo`, `bloquear`, `transferir`, `dar_acesso`, `excluir` |
| `conta` | `alterar` (correo, idioma, contraseña), `token_criar`, `token_revogar`, `aparelho_lembrar` (la aplicación en el celular: la clave de este aparato en una cookie, que entra sin la contraseña), `aparelho_esquecer`, `push_inscrever`, `push_cancelar`, `push_testar`, `excluir`; solo el master: `email_testar`, `vapid_gerar` |
| `colecoes` | `criar`, `renomear`, `excluir`, `arvore_copiar`, `arvore_renomear`, `arvore_excluir` |
| `acessos`, `convites`, `pedidos` | `remover`, `sair`; `convidar`, `aprovar`, `recusar`, `reenviar`, `cancelar`, `aceitar`; `pedir`, `aprovar`, `recusar`, `cancelar` |
| `cadastro` | sin login: `criar_conta`, `esqueci_senha`, `nova_senha` |
| `migracoes` | `aplicar` |
| `instalacao` | `instalar` (solo en una base vacía, con el token) |
| `backup` | `fazer` (una copia de seguridad ahora), `restaurar` (`arquivo=`: devuelve la base a esa copia, guardando antes una de lo que hay ahora) |
| `importacao` | `importar` (`banco=` el nombre de la base antigua; `substituir=1` cuando esta base ya tiene relojes; si se detuvo a medias, no queda nada guardado) |

**Las respuestas de error** dicen el motivo: `{"erro": "..."}` en las consultas, y `{"ok": false, "mensagem", "erros": [...]}`
(un error por motivo) en una escritura rechazada:

| Código | Cuándo |
|---|---|
| `400` | escritura rechazada por las validaciones (los errores dicen por qué), o una fórmula mal escrita en el `calcular` |
| `302` | `recurso=entrar` con el login correcto y `volta=`: va a la página |
| `401` | sin el token ni el login del sitio, o con ellos equivocados; sin ningún usuario (o con la base vacía), la respuesta dice qué hacer (`sem_usuarios` y `como`) |
| `403` | la copia de seguridad y las migraciones, por quien no es el master |
| `404` | recurso desconocido (la respuesta trae la lista de los recursos), la colección que usted no ve, o el reloj, la foto, el documento (o su archivo) o el `README.md` (en el `manual`) que no existe, o un archivo que no sale por el `codigo` (con `o_que_ver` en lo que queda fuera a propósito, como el `config.php`) |
| `409` | una eliminación sin `confirmar=1` (con `impacto`: lo que saldría junto; no se borró nada); la cuenta sin ninguna colección (`sem_colecao`); o la base de datos rechazó la escritura: un registro que otro todavía usa, un valor repetido, o un valor fuera de las reglas de validación de la base (viene el nombre de la regla) |
| `413` | el envío pasó del límite del PHP del servidor (`post_max_size`): un archivo demasiado grande |
| `500` | sistema parado (el `API_TOKEN` o el `FUSO` del `config.php` inválido) o un error interno |
| `503` | base desactualizada (solo responde `recurso=migracoes`, y la escritura `migracoes`/`aplicar`; y el `manual` y el `codigo`, que no usan la base), base vacía (antes de la instalación solo responde `instalacao`, y el `manual` y el `codigo`) o base caída |

### Los usuarios y las contraseñas {#os-usuários-e-as-senhas}

La contraseña no queda guardada en ninguna parte: queda solo su **hash**, hecho por el `password_hash` de PHP (bcrypt). El
hash tiene 60 caracteres, `$2y$12$...`: el `$2y$`, el costo (12 quiere decir 2¹² rondas), la sal (22 caracteres, sorteada para
cada contraseña) y el resultado (31). El hash no se deshace en contraseña: en el login, el sistema calcula el bcrypt de la
contraseña escrita con la sal y el costo que están en el hash y compara (`password_verify`). La misma contraseña da hashes
distintos cada vez, y todos coinciden.

**Para llevar los usuarios a otro sistema**, lleve el login y el `senha_hash` de cada uno (`recurso=usuarios`, por el master) y, allí,
compruebe la contraseña escrita contra el hash con el bcrypt del lenguaje: cada uno entra con la misma contraseña de siempre.
`$2y$` (PHP) y `$2b$` (los otros lenguajes) son el mismo cálculo; si la biblioteca rechaza el `$2y$`, cambie el comienzo por
`$2b$`. **Para traer usuarios de otro sistema**, envíe el hash de allí: `recurso=usuarios`, `acao=salvar`, `login` y
`senha_hash` (bcrypt `$2y$`, `$2b$` o `$2a$`, o argon2).

| Lenguaje | Comprobar la contraseña | Hacer el hash |
|---|---|---|
| PHP | `password_verify($senha, $hash)` | `password_hash($senha, PASSWORD_DEFAULT)` |
| Python (`pip install bcrypt`) | `bcrypt.checkpw(senha.encode(), hash.encode())` | `bcrypt.hashpw(senha.encode(), bcrypt.gensalt()).decode()` |
| Node (`npm install bcryptjs`) | `require("bcryptjs").compareSync(senha, hash)` | `require("bcryptjs").hashSync(senha, 12)` |
| Java (Spring Security) | `new BCryptPasswordEncoder().matches(senha, hash)` | `new BCryptPasswordEncoder().encode(senha)` |
| C# (BCrypt.Net-Next) | `BCrypt.Net.BCrypt.Verify(senha, hash)` | `BCrypt.Net.BCrypt.HashPassword(senha)` |

```sh
# el catálogo: login y hash de cada uno
curl -u usuario:contraseña "http://servidor/relojoeiro/api.php?recurso=usuarios"

# traer un usuario de otro sistema, con la contraseña que ya tenía
curl -u usuario:contraseña --data-urlencode recurso=usuarios --data-urlencode acao=salvar --data-urlencode login=maria \
     --data-urlencode 'senha_hash=$2b$12$...' http://servidor/relojoeiro/api.php
```

**Filtros genéricos** funcionan en cualquier lista de cualquier respuesta:

| Parámetro | Hace |
|---|---|
| `incluir=relogios,plano` / `excluir=motor` | solo esas partes, o todas menos esas |
| `f[lista][campo]=v` | igual a `v` (o a uno de varios: `v1,v2`) |
| `f[lista][campo][de]=` / `[ate]=` | rango de números o fechas |
| `f[lista][campo][contem]=` / `[diferente]=` / `[vazio]=1` | texto, exclusión, vacío |
| `busca[lista]=texto` | el texto en cualquier campo |
| `ordem[lista]=-campo` | ordena (el `-` invierte) |
| `limite[lista]=20&pagina[lista]=2` | paginación |
| `mostrar[lista]=id,nome` | solo esos campos |

Los campos anidados funcionan con punto, como `formulas.energia.valor`, `campos.loja.valor` y `compra.valor`. Los textos se
comparan sin distinguir mayúsculas ni acentos.

📖 **La documentación completa** (cada consulta, cada escritura con ejemplo y el diccionario de todos los campos de las
respuestas) está en la cabecera del [`api.php`](api.php) y en la propia API: `api.php?recurso=ajuda` (en el idioma de la
petición). El diccionario tiene una sola fuente, el de la ayuda; el de la cabecera se genera a partir de él
(`php testes/dicionario.php --gravar`).

---

## Instalación {#instalação}

**Requisitos:** PHP 8.1+ con `curl` y `openssl` (los webhooks, el correo, el Web Push, los calendarios y los secretos cifrados), un servidor web (nginx o Apache), el cron y
**una de estas bases de datos**, cada una por su extensión de PHP:

| Base | Extensión de PHP | Versión | Cuándo elegirla |
|---|---|---|---|
| **SQLite** | `sqlite3` | 3.35+ (probado en 3.53) | la más simple: un solo archivo, sin servidor de base. Sobra para una colección personal, incluso en una Raspberry Pi |
| **MySQL / MariaDB** | `mysqli` | con utf8mb4 (probado en MariaDB 11.4) | su hosting ya la tiene, o usted ya la usa |
| **PostgreSQL** | `pgsql` | 12+, con ICU, el predeterminado (probado en 18) | ya tiene un Postgres funcionando |
| **Firebird** | `pdo_firebird` | 3.0+ (probado en 3.0.11, con PHP 8.4) | ya tiene un Firebird funcionando |

El sistema se comporta igual en las cuatro. Lo comprueba la prueba de paridad ([`testes/`](testes/)), que ejecuta el mismo
guion en las cuatro bases y compara todo lo que devuelve la API.

```sh
# 1. el código
git clone https://github.com/Challado/relojoeiro.git /var/www/relojoeiro
cd /var/www/relojoeiro

# 2. la configuración: la base de datos (DB_TIPO), el token de la API, el huso horario, la carpeta de los documentos y, si
#    quiere, el correo, el Web Push, Outlook y las reglas del registro abierto (vea la tabla del config.php, abajo)
cp config.exemplo.php config.php
nano config.php
#    la carpeta de los documentos (DOCUMENTOS_PASTA), fuera de la carpeta publicada, con escritura para el usuario de PHP:
#    mkdir -p /var/lib/relogios2/documentos && chown www-data /var/lib/relogios2/documentos

# 3. la base vacía (solo en MySQL, Postgres y Firebird; SQLite crea el archivo solo)
#    MySQL:    mysql -u root -p -e "CREATE DATABASE relogios2 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
#    Postgres: createdb -U postgres relogios2
#    Firebird: echo "CREATE DATABASE 'localhost:/var/lib/firebird/3.0/data/relogios2.fdb' USER 'SYSDBA' PASSWORD '...'
#                    PAGE_SIZE 16384 DEFAULT CHARACTER SET UTF8;" | isql-fb -q

# 4. la instalación: la estructura y el conjunto inicial, traducidos para la base del config.php
php instalar.php --lingua=es
#    el conjunto inicial sale en ese idioma, que pasa a ser el predeterminado de la instalación (sin --lingua: portugués)
#    o por la API, con el token del config.php (el idioma: lingua=es, como en cualquier petición):
#    curl -H "X-Api-Token: $TOKEN" -d recurso=instalacao -d acao=instalar -d lingua=es http://servidor/relojoeiro/api.php

# 5. el primer usuario, el master, con su colección (la contraseña se pide sin que aparezca)
php criar_usuario.php su_login
#    o por la API, con el token:
#    curl -H "X-Api-Token: $TOKEN" -d recurso=usuarios -d acao=salvar -d login=su_login -d senha=... http://servidor/relojoeiro/api.php

# 6. el cron, cada minuto (es mudo: no escribe nada y no envía correo)
( crontab -l; echo "* * * * * php /var/www/relojoeiro/cron.php" ) | crontab -
```

El `cron.php` tiene dos opciones, para ejecutarlo a mano: `--forcar` ejecuta la vuelta de la mañana y la de la noche ahora,
aunque ya se hayan ejecutado hoy (arma la escala, envía los mensajes y sincroniza el calendario, si están activados), y `-v`
muestra en pantalla el registro que guarda (el mismo de la página Ejecuciones del cron). Ej.: `php cron.php --forcar -v`.

En SQLite, ponga el archivo de la base (`DB_ARQUIVO`) **fuera de la carpeta que publica el servidor web**, en una carpeta en
la que el usuario de PHP pueda escribir (SQLite crea a su lado los archivos `-wal` y `-shm`).

En Firebird, la base tiene que crearse en **UTF8** (el `DEFAULT CHARACTER SET UTF8` de arriba). PHP necesita la extensión
`pdo_firebird`: en Debian y Ubuntu, el paquete `php8.4-interbase` (su nombre antiguo) y el cliente de Firebird
(`libfbclient2`), que viene junto; en el `php.ini`, `extension=pdo_firebird`. El servidor, el paquete `firebird3.0-server` (o
el instalador del sitio de Firebird). En el `config.php`, `DB_NOME` es la ruta del archivo `.fdb` **en el servidor de la
base** (o un alias del `databases.conf`), y `DB_USUARIO`/`DB_SENHA` un usuario de Firebird (el `SYSDBA` o uno creado para el
sistema).

**7. El servidor web.** Por la web solo pueden abrirse las páginas, el `api.php` y lo que cargan (`estilo.css`, los `.js`, las
imágenes). Todo lo demás queda bloqueado: el núcleo (`lib.php`, `banco.php`, `operacoes.php`), el `config.php`, el cron, los
scripts de línea de comandos, las pruebas, los archivos `.sql`, `.json`, `.md` y `.sqlite`, y todo lo que empieza por punto,
incluida la carpeta `.git`, que viene con el `git clone`. El proyecto trae esto listo para los dos servidores:

- **Apache:** el [`.htaccess`](.htaccess) ya viene en la carpeta y funciona solo. La carpeta solo necesita `AllowOverride All`
  en la configuración de Apache, el predeterminado de casi todo hosting; sin él, el `.htaccess` se ignora y nada queda
  bloqueado. También hace funcionar el login del sitio cuando PHP corre por FastCGI (PHP-FPM, la mayoría de los hostings),
  caso en el que Apache no le pasaría la contraseña a PHP.
- **nginx:** copie el bloque del [`nginx-relogios.conf`](nginx-relogios.conf) dentro del `server` de su sitio (ajuste
  `/relojoeiro/` y la dirección de PHP-FPM). También trae el `client_max_body_size 512m`, para el envío de los documentos (vea
  [El tamaño de los envíos](#o-tamanho-dos-envios-os-documentos)).

Para comprobarlo, abra `https://su-servidor/relojoeiro/config.php` y `https://su-servidor/relojoeiro/.git/config`: los dos
tienen que dar **403**.

**8. Ábralo en el navegador** y entre con el usuario creado. El conjunto inicial ya está ahí: registre sus relojes en
**Hoy → Nuevo reloj**, elija el grupo de cada uno y rellene los campos. En **Mi cuenta**, ponga su correo. Las demás personas
entran por **Crear cuenta** (`cadastro.php`), por una invitación suya, o el master crea la cuenta en **Usuarios**.

### El `config.php` {#o-configphp}

| Constante | Para qué |
|---|---|
| `DB_TIPO` | la base: `mysql` (el predeterminado, vale para MariaDB), `pgsql`, `sqlite` o `firebird` |
| `DB_HOST`, `DB_PORTA`, `DB_NOME`, `DB_USUARIO`, `DB_SENHA` | el servidor de la base (MySQL, Postgres y Firebird); `DB_PORTA` 0 es el puerto predeterminado. En Firebird, `DB_NOME` es la ruta del archivo `.fdb` en el servidor (ej.: `/var/lib/firebird/3.0/data/relogios2.fdb`) o un alias del `databases.conf`, y `DB_HOST` vacío abre el archivo directo, sin servidor (el Firebird embebido) |
| `DB_ARQUIVO` | el archivo de la base (SQLite), fuera de la carpeta publicada |
| `API_TOKEN` | **obligatorio**, con 10 caracteres o más. Sin él el sistema entero se detiene y dice por qué. |
| `FUSO` | la zona horaria del sistema, como `America/Sao_Paulo`. Vacío o ausente: la de PHP (`date.timezone` en el php.ini). Es el huso de las fechas que guarda la base y el de quien no eligió otro; cada colección (Configuración) y cada persona (Mi cuenta) puede elegir el suyo (vea [El huso horario](#o-fuso-horário)) |
| `SEGREDO_CHAVE` | opcional: la clave que cifra en la base las contraseñas del CalDAV, las claves de Google, los tokens de Outlook y las cabeceras de los webhooks. Sin ella, el `API_TOKEN` (cambiar la clave pierde los secretos guardados: basta con escribirlos de nuevo) |
| `MSG_TITULO` | el título de los mensajes (el `{titulo}` de los webhooks, el asunto del correo, el título del Web Push). Por defecto: Relógios |
| `MSG_ENDPOINT`, `MSG_DESTINATARIO` | solo para la migración v25: la API de mensajes antigua (Telegram) se convierte en el primer destino webhook de la colección |
| `CADASTRO_ABERTO`, `DEMO_DIAS`, `CONVITE_DIAS` | opcionales: el registro abierto (`false`: cerrado, solo con invitación; sin la línea, abierto), los días de la cuenta de demostración (por defecto 30; `0`: la cuenta ya nace oficial) y los días que espera una invitación o una solicitud (por defecto 7) |
| `EMAIL_METODO`, `EMAIL_REMETENTE`, `SMTP_HOST`, `SMTP_PORTA`, `SMTP_SEGURANCA`, `SMTP_USUARIO`, `SMTP_SENHA` | el correo: `smtp` (el servidor indicado; la seguridad `tls`, por defecto, `ssl` o `nenhuma`) o `mail` (el `mail()` de PHP), y el remitente. Sin ninguno, no sale ningún correo |
| `IMAP_HOST`, `IMAP_PORTA`, `IMAP_SEGURANCA`, `IMAP_USUARIO`, `IMAP_SENHA`, `IMAP_PASTA_ENVIADOS` | opcionales: una copia de cada correo enviado en la carpeta Enviados de la cuenta |
| `POP_HOST`, `POP_PORTA`, `POP_SEGURANCA`, `POP_USUARIO`, `POP_SENHA` | opcionales: solo para que la prueba del correo revise el buzón por POP3 |
| `VAPID_PUBLICA`, `VAPID_PRIVADA`, `VAPID_ASSUNTO` | el Web Push: el par de claves (el master lo genera en **Mi cuenta**) y el asunto (`mailto:...`) |
| `OUTLOOK_CLIENTE_ID`, `OUTLOOK_CLIENTE_SEGREDO` | la aplicación registrada en Microsoft, para los calendarios de Outlook (vea abajo) |
| `DOCUMENTOS_PASTA` | la carpeta de los archivos de los documentos (el manual, la factura, fotos, videos), **fuera de la carpeta publicada**, con permiso de escritura para el usuario de PHP. Sin ella, la página Documentos avisa que falta y no acepta envíos. Ej.: `define("DOCUMENTOS_PASTA", "/var/lib/relogios2/documentos");` |
| `BACKUP_PASTA`, `BACKUP_MANTER`, `BACKUP_DOCUMENTOS` | opcionales: la copia de seguridad de la base (vea [Copia de seguridad](#cópia-de-segurança)). La carpeta, **fuera de la carpeta publicada** (sin ella, no hay copia automática); cuántas se guardan (predeterminado 7); y si lleva el contenido de los documentos guardado en la base (predeterminado: no). |
| `BACKUP_ATIVO` | opcional: `false` desactiva la copia de seguridad: sin copia automática, sin **Hacer una copia ahora** y sin el cuadro **Copia de seguridad** en la Configuración (ni el aviso de que falta la carpeta). Sin ella: activada |
| `DOCUMENTOS_LIMITE` | opcional: el mayor documento aceptado, en bytes. Sin ella, 100 MB (`104857600`); `0` o `-1`, sin límite del sistema. `MANUAL_LIMITE`, el nombre antiguo, todavía vale cuando ella no existe. El límite de PHP y el de nginx siguen valiendo (vea [El tamaño de los envíos](#o-tamanho-dos-envios-os-documentos)). Ej.: `define("DOCUMENTOS_LIMITE", 524288000);` para 500 MB |
| `ANTIGO_HOST`, `ANTIGO_PORTA`, `ANTIGO_USUARIO`, `ANTIGO_SENHA` | opcionales, solo para el `importar.php` con este sistema en Postgres, SQLite o Firebird: dónde está el MySQL del sistema anterior |
| `ALEATORIO_RANDOMORG_URL`, `ALEATORIO_ANU_URL` | opcionales, solo para pruebas: las direcciones de las fuentes de los números aleatorios (`https://www.random.org/integers/` y `https://qrng.anu.edu.au/API/jsonI.php`). Cambiadas, los sorteos piden los números a un servidor de prueba |
| `GOOGLE_TOKEN_URL`, `GOOGLE_API_URL`, `OUTLOOK_LOGIN_URL`, `OUTLOOK_GRAPH_URL` | opcionales, solo para pruebas: las direcciones de Google y de Microsoft que usan los calendarios. Cambiadas, la sincronización habla con un servidor de prueba |
| `TEXTOS_ESTRITO` | opcional, para quien está traduciendo: `true` hace que la traducción que falta se detenga con un error (vea [Los idiomas](#os-idiomas)) |

El sistema lee el `config.php` de su carpeta. Una variable de entorno del servidor, `RELOGIOS_CONFIG`, apunta a otro archivo en
su lugar: es lo que usa la prueba de paridad (`testes/paridade.sh`, con el [`testes/config.teste.php`](testes/config.teste.php)),
para ejecutarse sin tocar el `config.php` de verdad. En una instalación normal, no la defina.

### Destinos y calendarios en el servidor {#destinos-e-agendas-no-servidor}

Cada colección crea sus destinos y sus calendarios en las páginas **Destinos** y **Calendarios**. Lo que necesita
configuración en el servidor:

- **Telegram (un webhook):** cree un bot con @BotFather (él da el token) y averigüe su `chat_id` (envíe un mensaje al bot y
  abra `https://api.telegram.org/bot<token>/getUpdates`). En **Destinos**, un webhook: método `GET` o `POST`, dirección
  `https://api.telegram.org/bot<token>/sendMessage`, los campos `chat_id` = el número y `text` = `{mensagem}`. Cualquier otro
  puente (n8n, Home Assistant, su propia dirección) funciona igual: el sistema envía `{titulo}` y `{mensagem}` donde usted los
  puso, y lo da por enviado si la respuesta es 2xx. Los mensajes largos se dividen en partes.
- **Correo:** el servidor SMTP en el `config.php` (`SMTP_HOST`, el puerto, la seguridad, el usuario y la contraseña; en Gmail
  y en Outlook, una contraseña de aplicación) y el `EMAIL_REMETENTE`; o `EMAIL_METODO = "mail"`, con un sendmail en el
  servidor. Con el `IMAP_*`, cada correo enviado recibe una copia en Enviados. El master lo prueba en **Mi cuenta › Servidor
  de correo**: el cuadro muestra lo que el sistema leyó del `config.php` (el método, el remitente, el SMTP, el IMAP y el POP; las
  contraseñas solo como *definida* o no) y señala lo que está mal o falta (sin el `EMAIL_METODO`, el puerto que no combina con la
  seguridad, la contraseña vacía, el PHP sin `openssl`...). **Probar las conexiones** entra en el SMTP, en el IMAP (y revisa la
  carpeta Enviados) y en el POP sin enviar nada; **Enviar un correo de prueba** envía de verdad a la dirección que ponga. Después
  aparecen el resultado de cada parte y la **conversación con los servidores**, línea por línea (sin la contraseña), para
  encontrar dónde se detuvo.
- **Web Push:** el sitio tiene que abrirse por **https**. El master genera las claves en **Mi cuenta › Claves del Web Push** y
  copia las dos líneas al `config.php`. Después, cada persona activa las notificaciones en su aparato, en **Mi cuenta** (en el
  iPhone, primero añada el sitio a la pantalla de inicio), y un destino Web Push envía a los aparatos de quien él apunta.
- **Google Calendar:** en Google Cloud, cree una **cuenta de servicio** y descargue la clave JSON; comparta su calendario con
  el correo de la cuenta de servicio, con permiso para cambiar eventos; en **Calendarios**, pegue el JSON de la clave (queda
  cifrado en la base) y el ID del calendario. Solo el master puede, en lugar del JSON, indicar la ruta de un archivo en el
  servidor.
- **CalDAV (Nextcloud, iCloud, Fastmail):** la dirección del calendario, el usuario y la contraseña. En **iCloud**, una
  **contraseña de aplicación** (appleid.apple.com › Contraseñas de aplicación) y la dirección del calendario en
  `caldav.icloud.com`.
- **Outlook:** registre una aplicación en el portal de Microsoft (**Entra ID › Registros de aplicaciones**), con el permiso
  `Calendars.ReadWrite` y la dirección de retorno `https://<su servidor>/relojoeiro/api.php?recurso=outlook_retorno` (la
  página Calendarios muestra la dirección exacta); ponga el ID y el secreto en el `config.php` (`OUTLOOK_CLIENTE_ID` y
  `OUTLOOK_CLIENTE_SEGREDO`). En **Calendarios**, **Conectar con Outlook** lleva a la página de Microsoft y vuelve conectado.
  Sin la aplicación, use un enlace ICS: Outlook se suscribe a él.
- **Enlace ICS:** nada que configurar. El calendario da un enlace secreto para suscribirse en cualquier app (Google: **Otros
  calendarios › Desde URL**; Outlook: **Suscribirse desde la web**; iPhone: **Calendario suscrito**); **Cambiar el enlace**
  desactiva el anterior.

Fuera de las colecciones del master, las direcciones que alguien escribe (webhook, CalDAV) tienen que ser de internet: el
sistema rechaza las de la red del propio servidor (`127.0.0.1`, `192.168.x`...), para que nadie use el servidor para sondear
su red.

### El tamaño de los envíos (los documentos) {#o-tamanho-dos-envios-os-documentos}

Cada documento sube en un envío (varios archivos elegidos juntos suben uno por vez), y tres límites del servidor pueden
rechazar un archivo grande antes que el sistema. El sistema acepta hasta **100 MB** por archivo; el `DOCUMENTOS_LIMITE` del
`config.php` lo cambia (en bytes; `0` o `-1`: sin límite del sistema). Para videos del móvil, piense en unos 500 MB:

| Dónde | El límite | El predeterminado | Para 500 MB |
|---|---|---|---|
| `php.ini` | `upload_max_filesize` (el archivo) y `post_max_size` (el envío entero) | 2M y 8M | `upload_max_filesize = 500M` y `post_max_size = 512M` |
| nginx | `client_max_body_size` | 1m | `512m` (ya viene en el [`nginx-relogios.conf`](nginx-relogios.conf)) |
| `config.php` | `DOCUMENTOS_LIMITE` | 100 MB | `524288000` |

El archivo va directo a la carpeta de los documentos; la copia en la base va en trozos de 4 MB, leídos uno cada vez: el
`memory_limit` de PHP y el `max_allowed_packet` de MySQL (16 MB o más) no limitan el tamaño del archivo. La página
Documentos muestra el límite que vale de verdad (el del sistema, o menos por el `php.ini`; sin ningún límite, "de cualquier
tamaño"). Un archivo por encima de él se rechaza con el mensaje del tamaño; un envío por encima del `post_max_size` vuelve con
el código `413` y el límite del servidor. En Apache no hay límite que tocar, además del `php.ini`.

### Actualizaciones de la base {#atualizações-do-banco}

Después de actualizar el código, si hay una migración nueva (`migracao_v*.sql`), la API responde `503` y la página
**Configuración** muestra el botón para aplicarla (solo el master la aplica). El cron también se detiene y registra el motivo,
en vez de ejecutarse a medias. En una instalación nueva, el `schema.sql` ya trae la estructura de todas las migraciones, y el
conjunto inicial viene del `modelo.json`, cuando se crea el primer usuario.

**La migración v25 (varias personas)** rehace toda la base de datos: guarda antes una copia de todo en
`relojoeiro-antes-da-v25.jsonl.gz` (en la `BACKUP_PASTA`, o en la carpeta temporal), crea la estructura nueva y devuelve cada
registro en la forma nueva, con los mismos números (los relojes, los documentos y los registros siguen con su id de siempre).
El usuario más antiguo pasa a ser el master y el dueño de la colección y del árbol; los demás, miembros de la colección.
Telegram (`MSG_ENDPOINT`) pasa a ser el primer destino webhook, y Google Calendar, el primer calendario. Si se detiene a
medias, el mismo botón lo rehace a partir de la copia.

El `schema.sql` y cada migración son **un solo archivo para las cuatro bases**: la estructura (`CREATE TABLE`, `ALTER TABLE`)
se escribe en el dialecto de MySQL, y el [`banco.php`](banco.php) la traduce para Postgres, SQLite y Firebird
(`AUTO_INCREMENT`, `ENUM`, `TINYINT`, `DATETIME`, los `INDEX` dentro de la tabla, el `AFTER` y el `MODIFY COLUMN`, que en
SQLite rehace la tabla). Los datos (`INSERT`, `UPDATE`) van en SQL común a las cuatro: nada de `GROUP_CONCAT`, `IF()` o
`FIND_IN_SET`. `INSERT IGNORE`, `REPLACE INTO`, `NOW()`, `LEAST`, `GREATEST`, `LENGTH`, `DATE()`, `<=>`, `LIMIT`/`OFFSET`, el
`SELECT` sin `FROM`, el `INSERT` de varias filas y `CONCAT` están permitidos (el `CONCAT` sin argumento NULL: en Postgres el
NULL se vuelve texto vacío, en MySQL el resultado se vuelve NULL). Las migraciones v2 a v8 son de antes de esto y existen solo
para actualizar bases MySQL antiguas: una base Postgres, SQLite o Firebird ya nace en la versión actual.

Dos comandos que no existen así en ninguna base, el `banco.php` los entiende y los hace a la manera de cada una:

| Comando | Qué hace |
|---|---|
| `ALTER TABLE t ALTER FOREIGN KEY (coluna) REFERENCES outra(id) ON DELETE CASCADE` (o `SET NULL`) | cambia la clave externa de la columna (o la crea, si no había). En MySQL, Postgres y Firebird, busca en el catálogo el nombre de la clave antigua, la borra y crea la nueva (en Firebird, en una transacción); en SQLite, rehace la tabla. |
| `ALTER TABLE t DROP COLUMN coluna` | borra la columna junto con su clave externa y sus índices (SQLite rehace la tabla; Firebird borra antes también las reglas que usan la columna). |
| `ALTER TABLE t ADD CONSTRAINT nome CHECK (condição)` | la regla de validación, con nombre; si ya existe una con él, se cambia. Las filas que ya existen tienen que cumplir la regla nueva, si no la base la rechaza y sigue la regla de antes (en Postgres y Firebird, en una transacción; en MySQL, la nueva entra antes con un nombre provisional; en SQLite, la tabla se rehace en una transacción). |

Y, para que una migración que se detuvo a medias pueda ejecutarse de nuevo con el mismo botón: el `ADD COLUMN` de una columna
que ya existe y el `ADD INDEX` con el nombre de un índice que ya existe no hacen nada.

Una migración puede tener también un **paso en PHP**, para lo que el SQL común a las cuatro no hace (la v16 parte el texto de
las opciones de un campo, una por línea, en filas de la tabla `campo_opcao`; la v20, en MySQL/MariaDB, pasa a
`utf8mb4_unicode_ci` las tablas creadas en otra collation). Se ejecuta justo después del SQL, con el botón de la
Configuración, y también en el `instalar.php`.

### Diferencias entre las bases que puede notar {#diferenças-entre-os-bancos-que-você-pode-notar}

- **El orden de los textos en MySQL/MariaDB:** toda tabla nace en `utf8mb4_unicode_ci`, sea cual sea el predeterminado de la
  base. MariaDB viene con `utf8mb4_general_ci`, que ordena distinto de las otras bases (pone `dias_desde_compra` antes de
  `dias_de_carga`); una instalación antigua creada así la convierte la migración v20.
- **Mayúsculas y acentos:** como en MySQL, "Relógio" y "RELOGIO" son el mismo nombre, en la búsqueda, en el orden y en el
  login. En Postgres, esto viene de una collation ICU (`ci`) que crea el `instalar.php`. En SQLite, de una función de PHP; por
  eso, en un programa externo (el `sqlite3` de la línea de comandos, DB Browser), ordenar o comparar esas columnas da el error
  "no such collation sequence: ci". Leer y exportar funciona normalmente. En Firebird, de la collation `UNICODE_CI_AI`, en
  todas las columnas de texto.
- **En Firebird:**
  - los textos largos (`TEXT` en el `schema.sql`: las fórmulas, los mensajes, el registro del cron) llegan hasta **8191
    letras** (en MySQL, 65535 bytes), porque se vuelven `VARCHAR(8191)` (el `BLOB` de texto vuelve vacío como nulo en PHP);
  - los nombres de índice y de regla de más de 31 letras quedan solo con un trozo del md5 del nombre entero al final, y la
    tabla `no` es palabra reservada: en un programa externo (`isql-fb`, FlameRobin), escriba `"NO"`, entre comillas y en
    mayúsculas;
  - la clave única de varias columnas en la que una puede quedar vacía se convierte en un índice único de una expresión,
    porque en Firebird dos filas con el mismo valor y la otra columna vacía cuentan como repetidas (en MySQL, no);
  - la hora del `NOW()` viene de PHP (Firebird no tiene zona horaria por conexión), en el `FUSO` del `config.php`, como en
    las otras.
- **El orden de las columnas** de una migración con `AFTER` solo vale en MySQL: en las otras la columna nueva va al final de la
  tabla (cambia solo el orden de las claves en el JSON de la API).
- **Los ids** pueden saltar números distintos en cada base (MySQL reserva ids en bloque en algunos `INSERT ... SELECT`).

### Viniendo del sistema anterior {#vindo-do-sistema-anterior}

`php importar.php <base_antigua>` (o, por la API, `recurso=importacao`, `acao=importar`, `banco=<base_antigua>`) trae el
árbol, los relojes, las fotos, los usuarios, los campos y todo el historial (convertido en registros), en una sola
transacción: si se detiene a medias, no queda nada guardado. Con `--substituir` (en la API, `substituir=1`), borra antes lo
que ya existe en esta base. El [`PORTE.md`](PORTE.md) registra, punto por punto, cómo se portó cada comportamiento del sistema
antiguo.

---

<!-- parte 4 -->
## Cómo es por dentro {#como-é-por-dentro}

- **PHP 8.1+ puro, con SQLite, MySQL/MariaDB, PostgreSQL o Firebird.** Sin framework, sin Composer y sin dependencias, para
  funcionar en cualquier hosting con PHP. Cada base por su extensión de PHP (`sqlite3`, `mysqli`, `pgsql`, `pdo_firebird`); el
  SQL del sistema se escribe una sola vez y el [`banco.php`](banco.php) traduce lo que cambia de una base a otra.
- **Motor de fórmulas propio**, con análisis sintáctico, funciones de historial, versiones por grupo y dependencias entre
  fórmulas.
- **Simulación:** la escala y las previsiones simulan registros futuros y recalculan las fórmulas día a día, sin guardar nada.
- **Front-end en HTML y JavaScript puros.** Cada página PHP solo entrega el esqueleto; su `.js` llama a la API y arma la
  pantalla. **Nada sale antes del login:** la página comprueba el usuario y la contraseña en el servidor antes de la primera
  línea (`pagina_login()`, en `lib.php`, llamada por `topo()`) y, sin ellos, responde 401 con el pedido de contraseña del
  navegador, sin menú ni esqueleto (si la persona cancela, solo aparece "Entrar", con los enlaces **Intentar de nuevo** y
  **Crear cuenta**). El login vale para la página y para la API al mismo tiempo (el mismo realm). Solo pasan sin login **Crear
  cuenta** y, antes de que exista algún usuario, las páginas de la instalación. Los scripts llevan la fecha del
  archivo en la URL, para que la caché del navegador no sirva una versión antigua.
- **Cron mudo y rastreable:** se ejecuta cada minuto, no escribe en la salida y registra cada ejecución en la base (sin
  actividad, 7 días; con actividad o error, 1 año). Un error fatal va a la base o, si ni la base responde, a un archivo
  temporal.
- **Documentos a prueba de pérdidas:** cada archivo queda en la carpeta y, si se pide (por el sistema, por el reloj o por el
  archivo), entero, en la base; el cron recrea lo que desaparece, copia lo que falta y limpia lo que sobra (vea
  [Los documentos del reloj](#os-documentos-do-relógio)).
- **Una base que se defiende:** relacional y normalizada, con claves externas y reglas de validación (`CHECK`, `NOT NULL`,
  `UNIQUE`) en las cuatro bases: una escritura errónea la rechaza la propia base (vea [El modelo de la base](#o-modelo-do-banco)).
- **A prueba de fallos:** sin token válido o con la base desactualizada, el sistema se detiene y explica el motivo, en lugar de
  ejecutarse a medias.
- **Varias personas, separadas por la base de datos:** cada petición vale en una colección, y la capa de la base reescribe cada
  consulta para ver solo esa colección (y su árbol); ninguna pantalla o recurso necesita acordarse de filtrar. Una colección
  que usted no ve responde 404, como si no existiera.
- **Seis idiomas:** todo texto pasa por `t()`, con el portugués como clave y un archivo por idioma (vea
  [Los idiomas](#os-idiomas)).

### El modelo de la base {#o-modelo-do-banco}

La base de datos es relacional y normalizada (desde la migración v16; desde la v25, en 60 tablas): cada cosa en una tabla,
cada vínculo entre tablas una **clave foránea**, y ninguna lista guardada como texto dentro de un campo. Las cuentas
(`usuario`), las colecciones (`colecao`, con el dueño y la raíz del árbol), los miembros (`colecao_usuario`), el acceso a todo
de un dueño (`acesso_dono`), las invitaciones, las solicitudes, los tokens, los aparatos del Web Push, los destinos (`destino`
y una tabla por tipo) y los calendarios (`agenda` y una tabla por tipo). El árbol (`no`) tiene la raíz con el dueño y los
grupos apuntando al de arriba; sus definiciones (campos, tipos, fórmulas, avisos, categorías) apuntan a un grupo del árbol; lo
que es de la colección (relojes, modos, plan, eventos, configuración, accesorios) apunta a la colección. Los vínculos
opcionales se convirtieron en tablas de una fila (el reloj de un accesorio, el reloj fijo de un bloque, el bloque de un día
del plan). Borrar directamente en la base no deja nada suelto:

| El vínculo | Al borrar el de arriba | Dónde |
|---|---|---|
| obligatorio (la fila no existe sin la de arriba) | **desaparece junto** (`ON DELETE CASCADE`) | una cuenta se lleva sus colecciones y sus árboles; una colección se lleva los relojes, los modos, el plan, los destinos y los calendarios; un árbol se lleva las colecciones que lo usan; un grupo se lleva los grupos de dentro, los relojes, los campos, los tipos de registro, las fórmulas y sus avisos; un reloj se lleva los registros, los valores, las fotos, los documentos, el plan y sus criterios propios; un registro se lleva la medición del gasto; y así sucesivamente |
| opcional (la columna acepta vacío) | **el vínculo se deshace** (`ON DELETE SET NULL`) | el reloj de un evento personalizado, el grupo y el reloj fijo de un bloque de modo, el bloque de un día del plan, el tipo de registro que resuelve un aviso, el reloj de un accesorio (el accesorio queda suelto) |

Por la pantalla (**Grupos**, Eliminar), borrar un grupo sigue pasando antes lo que era suyo (los grupos de dentro, los
relojes, los campos, los tipos, las fórmulas y los avisos) al grupo de arriba; la cascada vale para quien borra directamente en
la base.

Las listas se convirtieron en tablas: los días de un bloque de modo (`modo_bloco_dia`) y de un evento semanal (`evento_dia`),
los tipos de archivo que acepta una categoría de documentos (`documento_categoria_aceita`), las opciones de un campo de lista
(`campo_opcao`) y, para cada destino y cada calendario, lo que reciben (`destino_aviso`, `destino_evento`, `agenda_aviso`,
`agenda_evento`). El modo en uso es la columna `ativo` del modo, y la medición del gasto llega al reloj por el registro que la
cerró. La tabla `config` guarda solo los valores sueltos de cada colección, y la `sistema`, los de todo el sistema.

Tres referencias van **por el nombre**, a propósito: las fórmulas y los criterios citan campos y fórmulas por el
identificador (es el texto que usted escribe, como `HORAS("pulso"; 30)`), y el `destino_aviso` y el `agenda_aviso` citan el
aviso por el identificador, porque un aviso puede tener varias versiones (una por grupo) y la elección vale para todas.
Cuando se borra la última versión de un aviso, el sistema borra junto sus filas.

**La base valida lo que guarda** (desde la migración v17), con los recursos del SQL estándar, y no solo el sistema: una
escritura errónea se rechaza venga de donde venga (la pantalla, la API, alguien tocando la base directamente). La API responde
`409`, con el nombre de la regla rota en el detalle.

| Recurso | Qué garantiza |
|---|---|
| `NOT NULL` | lo obligatorio está rellenado (el origen de cada registro y de cada día del plan, por ejemplo) |
| `CHECK` | cada valor dentro de lo que vale. Son 54 reglas, cada una con nombre (`ck_<tabla>_<qué>`) |
| listas cerradas (`ENUM`, que en las otras bases se vuelve `CHECK ... IN`) | los valores que acepta una columna: el formato del tipo de registro, la repetición del evento, el origen del registro (`manual`, `rodizio`, `importado`) y del día del plan (`sorteio`, `manual`)... |
| `UNIQUE` | lo que no puede repetirse: el identificador de un campo, de un tipo, de una categoría; el archivo de un documento; **como máximo un modo en uso** |
| `FOREIGN KEY` | los vínculos (arriba) |

Las reglas `CHECK`, por tema:

| Tema | La base rechaza |
|---|---|
| Textos | nombre, identificador, título, texto de aviso, fórmula y clave de la configuración vacíos o solo con espacios |
| Marcas sí/no | cualquier valor distinto de 0 y 1 (disponible, activo, exclusiva, mide el consumo, usada, envía, copia en la base, solo en los deseados...) |
| Relojes | una situación en la colección fuera de la lista (`colecao`, `desejado`, `emprestado`, `vendido`, `arquivado`); un reloj disponible fuera de la colección |
| Tipos de registro | sesión exclusiva fuera del formato sesión; "mide el consumo" fuera del formato con valor; "se cierra a las" fuera del formato sesión |
| Registros y mediciones | fin antes del inicio; consumo medido (tasa) fuera de 0 a 100%; horas negativas |
| Avisos | antelación fuera de 0 a 3650 días; horas simuladas en la escala fuera de 0 a 48 |
| Criterios | peso fuera de 0 a 100%; nota fuera de 0 a 100; un conjunto de criterios de un grupo **y** de un reloj a la vez; un rango que no es ni numérico (con "desde") ni de categoría; "desde" negativo; "hasta" que no pasa del "desde" |
| Modos de rotación | escala inteligente fuera de 7 a 730 días; día de la semana fuera de 1 a 7 |
| Eventos personalizados | lo que la repetición no usa (el día del mes fuera del mensual, el intervalo fuera de "cada N días", la fecha fuera de "una vez" y de "cada N días") y la falta de lo que usa; día del mes fuera de 1 a 31; intervalo fuera de 1 a 3650 días; día de la semana fuera de 1 a 7 |
| Accesorios | nombre vacío; un tipo fuera de la lista (`pulseira`, `caixa`, `estojo`, `ferramenta`, `carregador`, `peca`, `outro`) |
| Documentos | tamaño cero; SHA-256 que no tiene 64 caracteres; parte de la copia en la base por debajo de -1 (la -1 es la miniatura) |
| Foto del reloj | un tipo que no es imagen |
| Ejecuciones del cron | fin antes del inicio; duración negativa |

**Como máximo un modo en uso:** la marca `ativo` del modo es 1 en el que está en uso y vacía (`NULL`) en los otros, y la
columna tiene clave única. El SQL estándar deja repetir el vacío en una clave única, pero no el 1: dos modos en uso, la base
lo rechaza.

Dos reglas quedaron fuera por culpa de MySQL 8, que no acepta `CHECK` en la columna `id` (autoincremento) ni en una columna de
clave externa con `ON DELETE SET NULL`: "un grupo no es padre de sí mismo" (el sistema lo comprueba, y comprueba también los
ciclos mayores, que ningún `CHECK` alcanza) y las de los vínculos opcionales.

### Archivos {#arquivos}

| Archivo | Qué es |
|---|---|
| [`api.php`](api.php) | la API, la puerta de toda lectura y escritura (la autenticación, el formato, el orden de los recursos, los filtros, la respuesta), con la documentación completa en la cabecera |
| [`api/`](api/) | los recursos grandes de la API, un archivo cada uno (`hoje.php`, `ficha.php`, `configuracao.php`, `desejos.php`, `estatisticas.php`, `hora_certa.php`, `passaporte.php`, `galeria.php`, `ajuda.php`...), más sus funciones (`funcoes.php`) y las escrituras (`escrita.php`): se ejecutan dentro del `api.php`, y abiertos directamente no hacen nada (el `.htaccess` y el `nginx-relogios.conf` bloquean la carpeta) |
| [`lib.php`](lib.php) | el núcleo: login, árbol, motor de fórmulas, avisos, criterios, rotación, escala, documentos |
| [`colecoes.php`](colecoes.php) | las colecciones en el núcleo: el alcance de la petición, el `modelo.json`, los secretos cifrados, la tabla `sistema`, la migración v25 y el impacto de las eliminaciones |
| [`alertas.php`](alertas.php) | los destinos (webhook, correo, Web Push) y los calendarios (Google, CalDAV, Outlook, ICS) |
| [`contas.php`](contas.php) | las cuentas: el registro abierto, Mi cuenta, las colecciones y los árboles, las invitaciones, las solicitudes, los tokens y el panel del master |
| [`banco.php`](banco.php) | la base: la conexión con MySQL, Postgres, SQLite o Firebird, y la traducción del SQL de una a otra |
| [`operacoes.php`](operacoes.php) | las reglas de cada escritura: validaciones y mensajes |
| [`cron.php`](cron.php) | el plan, la sesión del día, las vueltas de la mañana y de la noche, los eventos, el calendario y el mantenimiento de los documentos |
| `index.php`, `plano.php`, `desejos.php`, `estatisticas.php`, `hora.php`, `acessorios.php`, `galeria.php`, `passaporte.php`, `ficha.php`, `documentos.php`, `historico.php`, `avisos.php`, `modos.php`, `configuracao.php`, `destinos.php`, `agendas.php`, `criterios.php`, `grupos.php`, `cadastros.php`, `execucoes.php`, `conta.php`, `aprovacoes.php`, `usuarios.php`, `cadastro.php` | las páginas (solo el esqueleto; `cadastro.php` es la pública) |
| `relojinho.js` | el relojito de la barra y del favicon: 24 tipos de reloj dibujados en canvas, con la hora exacta, uno por hora (en orden, sorteado o sin repetir) |
| `sw.js`, `offline.html`, `icone-*.png` | la aplicación en el celular: el *service worker* (entregado por `recurso=sw`, con la versión delante: el caché de las pantallas, la página sin conexión y el Web Push), la página **Sin conexión** y los íconos (192, 512 y 180 px) |
| `ajuda.php` | la página de ayuda: solo el esqueleto; el `ajuda.js` pide el texto a la API (`recurso=manual`: las secciones del README para quien usa, ya en HTML, convertidas en el `lib.php`) |
| [`pagina.php`](pagina.php) | la parte de arriba con el menú y el final de las páginas ; `topo()` comprueba el login antes de enviar nada (`pagina_login()`) |
| `api.js`, `hoje.js`, `painel.js`, `tabela.js`, `foto.js`, ... | las pantallas, armadas en el navegador a partir de la API |
| [`estilo.css`](estilo.css) | el aspecto |
| [`textos/`](textos/) | los idiomas: `funcoes.php` (la función `t()`, la lista de los idiomas, el idioma de la petición, las fechas y los números de cada idioma) y un archivo por idioma (`en.php`, `zh-CN.php`, `hi.php`, `es.php`, `fr.php`: el texto en portugués => la traducción, por parte); abiertos directamente no hacen nada |
| [`schema.sql`](schema.sql) | la estructura de la base de datos (solo la estructura: sin datos) |
| [`modelo.json`](modelo.json) | el conjunto inicial de una colección nueva (grupos, campos, tipos, fórmulas, avisos, criterios, categorías, modos, mensajes), sin códigos fijos |
| `migracao_v2.sql` … `migracao_v24.sql` | las migraciones, aplicadas por la página Configuración |
| [`config.exemplo.php`](config.exemplo.php) | el modelo del `config.php` |
| [`instalar.php`](instalar.php) | instala el `schema.sql` en la base del `config.php`, cualquiera de ellas (lo mismo que `recurso=instalacao`) |
| [`criar_usuario.php`](criar_usuario.php) | crea un usuario o cambia la contraseña, por la línea de comandos (lo mismo que `recurso=usuarios`, `acao=salvar`) |
| [`importar.php`](importar.php) | importa los datos del sistema anterior (lo mismo que `recurso=importacao`) |
| [`reconstrucao.php`](reconstrucao.php) | el guion para reescribir el sistema desde cero (el `recurso=reconstrucao` de la API) |
| [`.htaccess`](.htaccess) | la protección de los archivos internos en Apache (y el login del sitio con PHP por FastCGI) |
| [`nginx-relogios.conf`](nginx-relogios.conf) | el bloque de nginx que protege los archivos internos |
| [`testes/`](testes/) | la prueba de paridad: el mismo guion por la API en cada base, y el comparador de las respuestas; la comprobación del diseño de todas las pantallas, del móvil al ordenador, y del espejo entre la ficha y el panel de Hoy (el mismo orden de secciones y cuadros) (`layout.cjs`; `LINGUA=en` comprueba en un idioma); la de los diccionarios de los idiomas (`textos.php`); el armado del README de cada idioma (`readme.php`); y la comprobación de la arquitectura (`arquitetura.php`: las reglas que el código tiene que seguir) |
| [`docs/telas/`](docs/telas/) | las capturas de pantalla de este README |
| `README.md`, `README.en.md`, `README.zh-CN.md`, `README.hi.md`, `README.es.md`, `README.fr.md` | este README: el original, en portugués, y los otros idiomas. No se editan a mano: el [`testes/readme.php`](testes/readme.php) arma cada uno juntando el manual traducido (`textos/manual/<idioma>.md`, las secciones que muestra la Ayuda) y el resto traducido (`textos/readme/<idioma>.md`: el comienzo, Pruébelo, la API, la Instalación, Cómo es por dentro, Cómo contribuir y la Licencia) |
| [`PORTE.md`](PORTE.md) | el registro del porte de la versión anterior |

---

## Cómo contribuir {#como-contribuir}

Las mejoras son bienvenidas, y el camino es siempre este repositorio:

1. Para un cambio grande, **abra una issue antes** y cuente lo que pretende: evita trabajo que no va a entrar.
2. Haga un fork en GitHub, cree una rama y haga el cambio. El fork sirve solo para preparar la propuesta (vea la
   [licencia](#licença)).
3. **Siga la manera del código:** PHP y JavaScript puros, sin dependencias, comentarios y mensajes en portugués (las
   traducciones van en `textos/`). Lo que es conocimiento sobre relojes va al conjunto inicial (`modelo.json`), no al código.
4. ¿Cambió la base? Cree una `migracao_vN.sql` y regístrela en `$MIGRACOES` en el `lib.php`: la estructura en el dialecto de
   MySQL, los datos en SQL común a las cuatro bases (vea [Actualizaciones de la base](#atualizações-do-banco)).
5. ¿Cambió la API? **Explique cada campo nuevo** en el diccionario de la ayuda: el `"campos"` del `recurso=ajuda`, en el
   `api/ajuda.php` (la sección del recurso, con la ruta del campo como clave). Es la fuente única: el diccionario de la
   cabecera del `api.php` se genera a partir de él, con `php testes/dicionario.php --gravar` (no escriba en la cabecera a
   mano).
6. **Ejecute la prueba de paridad** ([`testes/`](testes/)) en las cuatro bases antes de enviar (GitHub también la ejecuta, en
   cada push al `main`: la prueba de paridad, la documentación y el diseño, en
   [`.github/workflows/testes.yml`](.github/workflows/testes.yml); el resultado aparece en la pestaña **Actions** y en el ✓ / ✗
   al lado de cada commit): `testes/paridade.sh`, con las bases en marcha, tiene que terminar con `Tudo igual.` También
   comprueba la documentación: la cabecera es lo que genera la ayuda, y todo campo de las respuestas está explicado. Comprueba la arquitectura ([`testes/arquitetura.php`](testes/arquitetura.php), que GitHub
   ejecuta también en un paso rápido, sin base): las páginas solo arman el esqueleto, la red de las pantallas es solo la API,
   la base solo la tocan el núcleo, la API y el cron, los archivos internos quedan bloqueados por la web, toda acción y toda
   constante del `config.php` están documentadas, y las otras reglas listadas al comienzo del archivo. Y falla si PHP da
   cualquier aviso (Warning, Notice, Deprecated) durante la vuelta. ¿Tocó las
   pantallas? Ejecute también la comprobación del diseño (`testes/paridade.sh --layout sqlite`): `0 problemas`.
7. **Todo texto nuevo pasa por `t()`** (el `api.js` y el `textos/funcoes.php` tienen la función), con el portugués como clave
   y los parámetros entre llaves: `t("Excluir {1}?", {1: nome})`, nunca `"Excluir " + nome + "?"`. Ningún texto escrito directamente en las pantallas ni en la API: GitHub lo comprueba de dos formas. La regla 11 de `testes/arquitetura.php` rechaza el texto entre comillas que va a la pantalla fuera de `t()` (lo que no se traduce a propósito, como la marca, va en `fixo("...")`), y `LINGUA=zh-CN testes/paridade.sh --textos sqlite` abre todas las pantallas con el sistema entero en chino y señala cualquier palabra en letra latina que no sea un dato de quien lo usa ni venga del diccionario. Después, tradúzcalo en los
   cinco archivos de `textos/` y ejecute `php testes/textos.php` (la prueba de paridad también lo ejecuta): tiene que terminar
   sin nada que falte. ¿Cambió el README? Sus traducciones llevan la marca de la versión que traducen (la primera línea de
   `textos/manual/<idioma>.md` y de `textos/readme/<idioma>.md`), y `php testes/textos.php` señala la que se quedó atrás:
   traduzca el trozo cambiado, ponga la marca nueva (`php testes/textos.php --manual` y `php testes/readme.php --original` dan
   el original con ella) y ejecute `php testes/readme.php`, que arma de nuevo los `README.<idioma>.md`.
8. Abra el pull request contando qué cambia para quien usa y cómo lo probó.

---

## Licencia {#licença}

Relojoeiro es **código fuente disponible**, no software libre: el código está a la vista, pero tiene dueño y reglas de uso. En
resumen (lo que vale es el texto del [`LICENSE`](LICENSE), en portugués):

| ✅ Puede | ❌ No puede |
|---|---|
| instalarlo y usarlo para usted, su familia o su empresa | crear un proyecto nuevo basado en este código, en todo o en parte, ni reescrito en otro lenguaje |
| cambiar el código de su instalación | distribuir el código, original o cambiado, fuera de este repositorio |
| proponer mejoras por pull request aquí | ofrecer el sistema como servicio a otras personas |
| hacer fork en GitHub para preparar esas propuestas | mantener un fork como proyecto aparte |

Para cualquier otro uso, pida autorización por GitHub ([Challado](https://github.com/Challado)).

> 💡 **Contribuya, no copie.** Ser contribuidor de Relojoeiro es mucho mejor que hacer un fork: su mejora entra para todos,
> con su nombre en el historial, y no corre ningún riesgo. En cambio, un fork que se vuelve proyecto propio viola la licencia,
> y los derechos sobre este código se defienden: petición de retirada en las plataformas, acción judicial con indemnización e
> incluso acción penal (la Ley brasileña 9.609/98 trata la violación del derecho de autor de software como delito). Incluso
> quien cree tener razón puede tener que defenderse, y eso cuesta tiempo y abogados. **Ante la duda, pregunte antes:** la
> respuesta es gratis, la discusión después no.

---

<p align="center"><sub>Hecho para quien tiene más relojes que muñecas. ⌚</sub></p>
