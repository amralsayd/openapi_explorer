# OpenAPI Explorer

Generates an OpenAPI 3 specification for the HTTP routes your Drupal site
exposes, and renders it as browsable documentation with an interactive request
tester.

The model is built from three sources, in order of authority:

1. **Route discovery** — the router and the REST resource plugins provide the
   paths, HTTP methods, authentication providers and owning module.
2. **Reflection** — the class and method behind each route give the `{token}`
   path parameters and a best-effort scan for the query keys the code reads.
3. **Docblock annotations** — everything that cannot be inferred from the code:
   summaries, parameter types, request and response schemas, examples.

Each operation is reported as *annotated*, *partial* or *none*, so a team can
see at a glance how much of its API is actually described.


## Table of contents

- [OpenAPI Explorer](#openapi-explorer)
  - [Table of contents](#table-of-contents)
  - [Requirements](#requirements)
  - [Installation](#installation)
    - [Trying it out](#trying-it-out)
  - [Configuration](#configuration)
    - [Trying an endpoint](#trying-an-endpoint)
      - [Obtaining a JWT](#obtaining-a-jwt)
  - [Annotations](#annotations)
    - [Tag reference](#tag-reference)
    - [Types](#types)
    - [Nested fields](#nested-fields)
    - [Class-level tags](#class-level-tags)
    - [Changing the tag prefix](#changing-the-tag-prefix)
    - [Validation](#validation)
  - [Extending the module](#extending-the-module)
  - [Troubleshooting](#troubleshooting)
  - [Maintainers](#maintainers)


## Features
- **Discovers your existing APIs** — scans the Drupal router and REST resource
  plugins to find the paths, HTTP methods, authentication providers and owning
  module of every route your site exposes, with no configuration required.
- **Docblock annotations for rich documentation** — annotate a controller
  method or a REST resource's verb method (`get()`, `post()`, `patch()`,
  `delete()`) to add summaries, parameter types, request and response schemas,
  enumerations, nested fields and examples, including sample request and
  response payloads.
- **Multiple authentication schemes** — recognises `basic_auth`, `key_auth`,
  `oauth2` (Simple OAuth), `jwt_auth` (JWT) and `cookie`, reading each optional
  module's real configuration instead of assuming defaults.
- **Interactive request tester** — issue real requests straight from the
  documentation page, entering credentials once per scheme and picking a scheme
  per operation. Nothing entered is stored.
- **Flexible JWT handling** — supply a token by pasting it, fetching it from a
  token URL, or signing it in the browser from claims and a private key
  (RS256/384/512 and HS256/384/512), with the key never leaving your browser.
- **OpenAPI 3 specification output** — serves the generated spec next to the
  documentation as `openapi.json` and `openapi.yaml` for client generators and
  external viewers.
- **Coverage reporting** — each operation is reported as *annotated*, *partial*
  or *none*, so a team can see at a glance how much of its API is described.
- **Configurable scanning** — choose which modules, sources and path prefixes to
  document, include or exclude REST resources and administrative routes, and cap
  the number of endpoints discovered.
- **Configurable tag prefix** — the `oapi` docblock prefix can be changed to
  match code already annotated with another prefix, with no docblock edits.
- **Annotation validation** — malformed annotations never break the page; they
  are collected and shown in an *annotation warnings* panel.
- **Extensible via alter hooks** — register shared schema components, overlay
  curated documentation, or supply fallback response schemas.
  
## Requirements

This module requires no other modules. It uses only Drupal core services and
the Symfony components core already ships.

These modules are *optional*. When present, the module reads their real
configuration instead of assuming anything:

- **REST** (core) — to document routes served by REST resource plugins.
- **Basic Auth** (core) — adds the `basic_auth` security scheme.
- **[Key auth](https://www.drupal.org/project/key_auth)** — adds the
  `key_auth` scheme, using the header name that module is configured with.
- **[Simple OAuth](https://www.drupal.org/project/simple_oauth)** — adds
  the `oauth2` scheme, using that module's real token endpoint.
- **[JWT](https://www.drupal.org/project/jwt)** — adds the `jwt_auth` scheme.
  Its `jwt_auth_issuer` submodule also gives the tester a token endpoint to
  fetch from.


## Installation

Install as you would any contributed Drupal module. See
[Installing modules](https://www.drupal.org/docs/extending-drupal/installing-modules)
for further information.

### Trying it out

The **OpenAPI Explorer example API** submodule adds four read-only sample
endpoints under `/api/example`, so there is something real to look at before you
annotate your own code:

| Endpoint | Kind | Filters |
| --- | --- | --- |
| `GET /api/example/nodes` | Controller route | `type`, `tags`, `tags_field`, `page`, `limit` |
| `GET /api/example/users` | Controller route | `roles`, `status`, `page`, `limit` |
| `GET /api/example/rest/nodes` | REST resource | the same, plus `_format` |
| `GET /api/example/rest/users` | REST resource | the same, plus `_format` |

Each pair is backed by one shared service, so the controller route and the REST
resource behave identically and differ only in how they are exposed. Between
them they exercise every tag the grammar supports — class-level inheritance,
enumerations, nested response fields, a registered component catalog and a
response example — which makes the submodule a working reference for annotating
your own endpoints. It requires `node`, `user`, `taxonomy`, `rest` and
`serialization`; the main module requires none of them. Uninstall it once you
have seen how it works.


## Configuration

Visit **Configuration › Web services › API documentation** to browse the
documentation, and its **Settings** tab to control what is documented.

- **Which modules to scan** — either pick whole extension sources (custom,
  contributed, profile, core) or tick individual modules.
- **Path prefixes** — one per line, for example `/api`. Only routes under
  one of these prefixes are documented. **Leave the list empty to document
  every route on the site**, which combined with selecting every source gives
  a complete picture of the APIs a site exposes.
- **Include REST resources** / **Include administrative routes** —
  administrative pages are excluded by default, being rarely part of an API.
- **Maximum number of endpoints** — discovery stops once this many endpoints
  have been found, which keeps the page responsive on a site-wide scan. Set it
  to 0 for no limit.
- **Docblock tag prefix** — see below.
- **JWT header name** — the header a JSON Web Token is sent in, used by the
  tester and written into the specification. This cannot be detected, so it is
  configured: leave it as `Authorization` unless your site reads another header.
  Drupal's JWT module accepts `JWT-Authorization` as well, and some sites patch
  it to require that one.
- **Specification metadata** — the title, version, description and server URLs
  written into the generated specification. An empty title uses the site name.
- **Interactive tester** — turn off to render documentation only.

Two permissions are provided, both restricted: *View the API documentation*
(the documentation page and the specification routes) and *Administer the API
documentation* (the settings form). The documentation names the classes and
methods behind each route, so grant the first only to trusted roles.

### Trying an endpoint

The documentation page can issue real requests. Enter credentials once in the
**Authentication credentials** panel, where each scheme has its own tab, then
pick a scheme per operation:

| Scheme | What the tester sends |
| --- | --- |
| `key_auth` | The API key in the configured header. |
| `basic_auth` | `Authorization: Basic …` from the username and password. |
| `jwt_auth` | `Bearer <token>` in the configured JWT header. See below for the three ways to obtain the token. |
| `oauth2` | `Authorization: Bearer …`, fetching a token by client credentials when the bearer field is empty. |
| `cookie` | Your session cookie, plus an `X-CSRF-Token` on unsafe methods. |

Nothing entered there is stored, and cookies are sent only for the `cookie`
scheme and when fetching a JWT, which needs your session to authenticate.

#### Obtaining a JWT

The JWT block offers three sources, chosen with *Where the token comes from*:

1. **A token I paste** — for a token minted elsewhere, by your identity
   provider or by `drush`.
2. **Fetched from a token URL** — calls an endpoint that issues a token for your
   current session, such as the `/jwt/token` route of the JWT module's
   `jwt_auth_issuer` submodule. The response may spell the token `token`, `jwt`
   or `access_token`.
3. **Signed here from claims and a private key** — you supply a claim set and a
   signing key, and the browser mints the token itself with the Web Crypto API.
   Useful when the site only *verifies* tokens, holding a public key, and an
   issuer is not available to you.

For signing:

- RS256/384/512 and HS256/384/512 are supported.
- An RS\* key must be **PKCS#8**, the form that begins with
  `-----BEGIN PRIVATE KEY-----`. Browsers cannot import the older PKCS#1
  `BEGIN RSA PRIVATE KEY` form; convert
  it with `openssl pkcs8 -topk8 -nocrypt -in key.pem -out key-pkcs8.pem`.
  Passphrase-protected keys must be decrypted the same way.
- `iat` and `exp` are added when your claims omit them, and every request is
  signed afresh so a token cannot expire part-way through a session.
- **The key is used only by your browser and is never sent to the server.**
  Signing needs a secure context, so the page must be on HTTPS or localhost.
- For the JWT module, the claim that identifies the account is
  `{"drupal": {"uid": 1}}`, which is what the field is prefilled with.

The raw specification is served next to the documentation page as
`openapi.json` and `openapi.yaml`, behind the same permission, for client
generators and external viewers.


## Annotations

Add tags to the docblock of a controller method, or of a REST resource's verb
method (`get()`, `post()`, `patch()`, `delete()`).

```php
/**
 * Lists articles.
 *
 * @oapiSummary List the published articles.
 * @oapiDescription Returns a page of articles, newest first.
 * @oapiCategory Content
 * @oapiTag public
 * @oapiParam query status string(draft|published) - Filter by status.
 * @oapiParam query page integer - The zero-based page index.
 * @oapiParam path id integer required - The article id.
 * @oapiRequestField title string required - The article title.
 * @oapiRequestField tags[] string - Free-text tags.
 * @oapiResponse 200 PaginatedResponse - A page of articles.
 * @oapiResponseField data[].id integer - The article id.
 * @oapiExample 200 {"data":[{"id":1}],"meta":{"total":1}}
 * @oapiResponse 404 ErrorResponse - No such article.
 */
```

### Tag reference

| Tag | Purpose |
| --- | --- |
| `@oapiSummary <text>` | One-line title for the operation. |
| `@oapiDescription <text>` | Longer explanation. |
| `@oapiCategory <name>` | The sidebar group the endpoint is filed under. |
| `@oapiTag <name>` | Adds an OpenAPI tag. Repeatable. |
| `@oapiOperationId <id>` | Overrides the generated `operationId`, so client generators produce stable method names. |
| `@oapiSecurity <scheme>…` | Security requirement for this operation, overriding the route's own `_auth`. `@oapiSecurity none` marks it public. |
| `@oapiDeprecated [<reason>]` | Marks the operation deprecated. |
| `@oapiInternal` | Removes the operation from the documentation *and* the specification. |
| `@oapiParam <in> <name> <type> [required] - <description>` | A parameter, where `<in>` is `path`, `query` or `header`. Path parameters are always required. |
| `@oapiRequestField <name> <type> [required] - <description>` | A field of the JSON request body. |
| `@oapiRequestExample <inline JSON>` | Prefills the tester's body editor and the specification's request example. |
| `@oapiResponse <status> [Component] - <description>` | A response. The optional component name references a shared schema. |
| `@oapiResponseField <name> <type> - <description>` | A field of the most recently declared response, defaulting to `200`. |
| `@oapiExample <status> <inline JSON>` | A response example. |

A ` - ` (space, dash, space) separates the machine-readable tokens from the
free-text description, which is always optional.

### Types

Beyond `string`, `integer`, `number`, `boolean`, `array` and `object`:

| Written as | Becomes |
| --- | --- |
| `datetime`, `date`, `time`, `email`, `uri`, `uuid`, `password`, `binary`, `ipv4`, `ipv6` | A string with the matching OpenAPI `format`. |
| `string(draft\|published)` | An enumeration. Works with any primitive type. |
| `array<string>`, `array<ErrorResponse>` | A typed array. |
| `ErrorResponse` or `$ErrorResponse` | A reference to a shared component. |

### Nested fields

Request and response field names may describe structure with dots and `[]`, so
one line per leaf is enough. These three lines:

```php
 * @oapiRequestField data.items[].id integer required - The item id.
 * @oapiRequestField data.items[].label string - The item label.
 * @oapiRequestField data.total integer - How many items there are.
```

produce an object `data`, containing an array `items` of objects with `id` and
`label`, alongside an integer `total`.

### Class-level tags

Tags on the class docblock apply to every method in that class, so shared
values need only be written once. Method-level tags win, and lists such as
`@oapiTag`, `@oapiParam` and `@oapiResponse` are merged.

```php
/**
 * Article endpoints.
 *
 * @oapiCategory Content
 * @oapiSecurity key_auth
 * @oapiResponse 403 ErrorResponse - Access denied.
 */
class ArticleController { /* ... */ }
```

### Changing the tag prefix

The `oapi` prefix is configurable on the settings page. A site whose code is
already annotated with another prefix can adopt this module without editing a
single docblock: set the prefix to the one already in use.

### Validation

Malformed annotations do not break the page. They are collected and shown in an
*annotation warnings* panel above the documentation: unknown tags, status codes
that are not three digits, references to components that do not exist, path
parameters that do not match the route, and invalid JSON in examples.


## Extending the module

Three alter hooks are documented in `openapi_explorer.api.php`:

- `hook_openapi_explorer_schema_components_alter()` — register the response
  envelopes your own API uses, so annotations can reference them by name.
- `hook_openapi_explorer_endpoints_alter()` — overlay curated documentation on
  the discovered set, remove endpoints, or add ones that cannot be discovered.
- `hook_openapi_explorer_envelope_alter()` — supply a fallback response schema
  for operations that carry no annotation.

To read the documentation from your own module, depend on
`Drupal\openapi_explorer\Doc\ApiDocProviderInterface` and inject the builder
optionally, so your module keeps working when this one is absent:

```yaml
my_module.my_service:
  class: Drupal\my_module\MyService
  arguments: ['@?openapi_explorer.builder']
```


## Troubleshooting

**No endpoints are listed.** Check the path prefixes and the selected modules on
the settings page. The defaults only look at custom and contributed modules
under `/api`.

**An endpoint is listed but shows no schema.** It has no annotations yet. The
class and method it resolved to are shown under the path, which is where the
tags belong.

**Annotations were added but nothing changed.** The documentation is cached;
rebuild the cache. Confirm that the tag prefix on the settings page matches the
tags in the code.

**The list says it is incomplete.** The endpoint limit was reached. Narrow the
path prefixes or raise the limit.

**A request from the tester fails with a network error.** The browser sends
these requests directly, so the endpoint must be on the same origin and, for
cookie authentication, accept the session cookie.


## Maintainers

- See the project page on drupal.org.
