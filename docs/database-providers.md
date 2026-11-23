# Database providers

Ghoti supports MySQL/MariaDB, internal SQLite, and MongoDB. Existing installs
keep their configured MySQL database. Selecting another provider opens a separate
database; it does **not** copy existing users, pages, uploads, or store records.
A fresh database provisions all module schemas and seed content on first use.
A fresh directory opens browser setup with SQLite. For a managed database, create
the first administrator using `php bin/create-admin.php USERNAME EMAIL`.

## Configuration

Configuration precedence is provider defaults, then `db.config.local.php`, then
explicit `GHOTI_DB_*` environment variables. Empty environment values count as
explicit overrides. The local file is ignored by Git. When `GHOTI_DB_DRIVER`
selects a different provider than the local file, that file's connection values
are ignored so MySQL credentials and ports cannot accidentally reach MongoDB.

| Setting | Environment | Purpose |
| --- | --- | --- |
| `driver` | `GHOTI_DB_DRIVER` | `mysql` (default), `sqlite`, or `mongodb` |
| `host` | `GHOTI_DB_HOST` | Default `127.0.0.1` |
| `port` | `GHOTI_DB_PORT` | MySQL `3306`; MongoDB `27017` |
| `database` | `GHOTI_DB_NAME` | Database name, default `ghoti` |
| `username` | `GHOTI_DB_USER` | MySQL default `ghoti`; MongoDB default empty |
| `password` | `GHOTI_DB_PASSWORD` | Password, no shipped credential |
| `charset` | `GHOTI_DB_CHARSET` | MySQL charset, default `utf8mb4` |
| `sqlite_path` | `GHOTI_DB_SQLITE_PATH` | Absolute SQLite path; default `.ghoti-data/ghoti.sqlite` under the application |
| `authSource` | `GHOTI_DB_AUTH_SOURCE` | MongoDB authentication database, default `admin` |
| `replicaSet` | `GHOTI_DB_REPLICA_SET` | MongoDB replica-set name; empty permits discovery |
| `tls` | `GHOTI_DB_TLS` | MongoDB TLS, default `true`; `false` only for a server intentionally configured without TLS |
| `tlsCAFile` | `GHOTI_DB_TLS_CA_FILE` | Absolute path to the CA certificate on the PHP server; empty uses system trust |

Set database variables in the PHP web worker's environment as well as the CLI
environment when using command-line tools. `GHOTI_DB_CONFIG` optionally points
to a trusted operator-owned PHP configuration file in place of the default
local file. When an existing installation loses its database connection, the site returns HTTP 503. Repair
the connection through the operator configuration.

## Internal SQLite

Enable PHP's `pdo_sqlite` extension. Open a fresh Ghoti directory in your browser
and create the first administrator. PHP needs write access to the installation
directory. The installer creates a database under `.ghoti-data/` and records its
location in `db.config.local.php`.

For a custom SQLite path or managed configuration, use the environment
or the local file, then run `php bin/create-admin.php admin admin@example.com`.
For example, a local config that selects the internal SQLite path contains:

```php
<?php
return array('driver' => 'sqlite');
```

Or set `GHOTI_DB_DRIVER=sqlite`. Ghoti creates `.ghoti-data/ghoti.sqlite`, uses WAL
journaling and a five-second busy timeout, and stores advisory lock files beside
the database. The PHP worker needs write access to that directory. The directory
is private runtime state: it is ignored by Git, excluded from site ZIPs, hidden
by the file manager, and blocked by the existing Apache hidden-directory rule.
On nginx or another server, deny HTTP access to `.ghoti-data/` and hidden files.
Prefer an absolute path outside the document root when custom server rules do
not provide this protection. Keep the database on a **local disk**, not NFS or a
shared network filesystem. Multiple web hosts should use a server provider.

SQLite's NOCASE collation handles ASCII case folding; non-ASCII comparisons may
differ from MySQL's Unicode collation. SQLite is suited to one-host deployments
with modest write concurrency. `:memory:` is accepted for tests only; it does not
persist across requests.

## MongoDB

Enable PHP's `mongodb` extension. No Composer library is required. The adapter
uses the native driver and requires MongoDB 5.0 or newer. A local override is:

```php
<?php
return array(
    'driver' => 'mongodb',
    'host' => 'mongo.example.test',
    'port' => '27017',
    'database' => 'ghoti',
    'username' => 'ghoti',
    'password' => '',
    'authSource' => 'ghoti',
    'replicaSet' => 'ghoti-rs',
    'tls' => true,
    'tlsCAFile' => '/etc/ghoti/mongodb-ca.crt', // Install the server CA on the PHP host.
);
```

Supply the account and password through local configuration or the environment
when authentication is enabled. TLS verifies both the server certificate and its
hostname/IP. For a private CA, install its public certificate on the PHP host and
set `tlsCAFile` to that local path; the database server's path is not automatically
available to PHP. There is no option to bypass certificate verification. The
[PHP driver TLS options](https://www.php.net/manual/en/mongodb-driver-manager.construct.php)
describe how trust is configured. Use a dedicated application database. The
account needs data read/write, collection/index creation, and collection-drop
privileges for restore (the database-scoped `readWrite` role covers these).
Verify the host's bind address and firewall allow the PHP server to reach the
configured port.

MongoDB must be a replica set or sharded cluster. A **single-node replica set**
is sufficient. Setup rejects standalone deployments because store orders span
collections and account locks must remain atomic across workers. See MongoDB's
[transaction requirements](https://www.mongodb.com/docs/manual/data-modeling/enforce-consistency/transactions/)
and [standalone-to-replica-set procedure](https://www.mongodb.com/docs/manual/tutorial/convert-standalone-to-replica-set/).

Each module table maps to a collection. `_ghoti_schema` tracks column defaults
and keys, `_ghoti_counters` allocates numeric IDs atomically, and `_ghoti_locks`
coordinates account operations inside transactions. Keep these collections with
the application data when using native MongoDB backups. Indexes preserve primary
and unique keys. Queries use case-insensitive English collation (strength 2);
its Unicode behavior can differ from MySQL. Conditional updates, such as spending
TOTP steps or claiming downloads, execute atomically on the server.

## Queries, upgrades and recovery

Modules retain the existing prepared-query and numeric-recordset API. MySQL uses
PDO directly; SQLite translates schema/upsert syntax; MongoDB compiles the
application's bounded SQL dialect into native filters, updates and aggregation
pipelines. It supports the joins, correlated counts, grouping, DISTINCT, CASE,
ordering and limits used by the current modules. It is **not a general SQL
engine**: unsupported statements/functions throw an error. New SQL features must
be implemented and tested across the providers before modules use them. Values
remain bound/literal throughout translation.

The MySQL provisioning marker is scoped to its connection identity. SQLite and
MongoDB check their live schemas, preventing a marker from another database from
suppressing bootstrap. Upgrades add columns and backfill defaults. They do not
rename, drop or retype existing columns.

The existing database export/restore flow works with each provider and labels
new dumps with the source provider. MongoDB exports its application collections
as Ghoti SQL; SQLite exports its native schema and indexes. Restore requires the
**same provider**; older unlabeled dumps are MySQL. This is a recovery mechanism,
not a cross-provider migration tool. The site ZIP excludes internal SQLite data;
keep the database export with the ZIP. Native database backups remain useful for
large deployments and preserving full server metadata.

## Verification

`php tests/database-config.php` checks defaults, overrides, validation, setup
selection and parser rejection without connecting to a database.

`tests/database-providers.php` must be explicitly enabled and pointed at a
**disposable, empty** database. It inserts and restores test data. For example:

```sh
GHOTI_DB_TEST=1 GHOTI_DB_DRIVER=sqlite \
  GHOTI_DB_SQLITE_PATH=/tmp/ghoti-provider-test/ghoti.sqlite \
  php tests/database-providers.php
```

For MongoDB/MySQL, set the corresponding host, port, database and credentials.
For a disposable MongoDB instance without TLS, explicitly set `GHOTI_DB_TLS=false`;
production servers requiring TLS should use their trusted CA certificate.
The integration suite checks all module schemas, seed data, literal preservation,
identity allocation, joins, analytics, upserts, transactions, competing account
locks, existing module APIs, additive migrations, and backup/restore round trips.
It has been run against SQLite, MongoDB 8.0 as a single-node replica set, and
MariaDB 11 using disposable containers. Run the existing CLI regression suites
alongside it.
