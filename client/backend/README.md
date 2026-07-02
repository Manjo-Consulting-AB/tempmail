# Backend scaffold

The backend scaffold now uses MySQL for client script storage.

- create script
- list scripts
- add/remove whitelist and blacklist patterns
- update greylist days
- schedule pending sync
- cancel pending sync
- run pending syncs
- dispatch signed webhooks with delivery feedback

## Database migration

Apply [sql/migrations/004_create_client_scripts.sql](../../sql/migrations/004_create_client_scripts.sql) to enable the MySQL-backed storage layer.

The new table stores:

- script metadata
- whitelist and blacklist lists as JSON
- sync state and the latest webhook result
- the live-by-default `dry_run` flag used internally by the agent

## Delivery feedback

When a change is saved, the backend now attempts to deliver the update to the client immediately. The response is stored on the script so the UI can show whether the client accepted the update or whether the user should retry or contact support.
