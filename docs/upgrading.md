# Upgrading an existing install

Deploy the new code and load any page. Module tables are brought up to date on
the first request that touches them; there is no migration command to run.

## What happens on the first request

Each module ships its schema as `mod/<module>/<module>.sql`, and `ghotidb::
loadModuleSql()` keeps the database matching it:

1. **New tables are created.** Every statement in those files is
   `create table if not exists`, so running the file is a no-op for tables that
   already exist and creates any the module has gained.
2. **New columns are added.** The file is parsed and compared against the live
   table; anything missing is added with the definition the file declares. This
   is strictly additive — nothing is dropped, renamed, retyped or reordered, so
   it cannot destroy data.
3. **Seed rows are inserted only for tables created from scratch**, so an
   upgrade never overwrites configuration you have already saved.

`db.provisioned.json` records, per module, the fingerprint of the schema file the
database currently matches. The common case — nothing deployed since the last
request — costs one `stat` per module and no database work. The upgrade pass runs
when a deployment changes a schema file, and only then.

The file is a cache and is safe to delete: doing so forces a full re-check on the
next request. It is untracked, so it never travels between installs.

## Why this exists

`create table if not exists` does nothing at all to a table that already exists.
Before this, upgrading a site left older tables missing every column added since
they were created, and the only symptom was a runtime failure in whatever read
them first:

```
ERROR [mail.db.php:getSettings]: PDOException: SQLSTATE[42S22]:
Column not found: 1054 Unknown column 'tlsVerify' in 'SELECT'
```

Mail settings could not be read or saved, and the fix was hand-written SQL.

## What it will not do for you

- **`AUTO_INCREMENT` columns cannot be added** by `ALTER TABLE` without a key in
  the same statement. If a module ever adds one to an existing table, the upgrade
  logs that it skipped the column and names it; that table has to be rebuilt by
  hand.
- **Type and default changes are not applied.** Only missing columns are added.
  A column whose definition changed keeps the type it has.
- **Nothing is removed.** Columns and tables from features that were deleted stay
  where they are, holding their data, until you drop them yourself.

## If an upgrade goes wrong

Check `ghoti.log` for `ghoti.db.php:ensureModuleSchema`. It logs each column it
adds at INFO, and anything it could not apply, with the statement it tried.
Applying that statement by hand and reloading is usually the whole repair.

Back up the database before deploying, as always. The upgrade pass only issues
`ALTER TABLE … ADD COLUMN`, but a backup is what makes that claim cheap to verify
rather than something you have to trust.
