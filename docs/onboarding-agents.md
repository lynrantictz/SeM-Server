# Onboarding agents

Operations administrators manage agents under **Users and access → Onboarding agents**.
Agents use the Business portal only. They can register vendors and configure assigned
businesses without becoming vendor owners. Public registration remains disabled.

## Activate the feature

From `server/`, after checking that your environment targets the intended local database:

```sh
php artisan migrate --path=database/migrations/2026_10_24_000001_create_onboarding_agent_tables.php
php artisan db:seed --class=OnboardingAgentRoleSeeder
php artisan route:clear
```

These commands add the new schema and role/permissions. Do not use `migrate:fresh`,
`db:wipe`, or the full database seeder to activate this feature. The role seeder
adds permissions to existing super-admin and operations-admin roles; it does not
create accounts or change other roles. The full role seeder also includes it for
new installations.

Invitations are queued email notifications. Configure the Business portal URL,
mail transport and queue worker (`php artisan queue:work`). Local log mail does
not deliver email; check the local mail preview/log when using that transport.
In local development only, agent activation links are also written immediately to
`storage/logs/agent-invitations.log`, before queue processing. Create or resend an
agent invitation in Operations, then read that file. No activation secrets are
logged in testing, staging or production.

Invitation links contain secrets: do not publish logs or links.

## Access model

* Account type `agent`; role `onboarding-agent`.
* `agent_vendor_assignments` is separate from `vendor_user` ownership.
* Scope `all_businesses` covers the vendor and its future businesses.
* Scope `selected_businesses` uses `agent_business_assignments` and excludes
  sibling businesses from profile responses, direct API access and the workspace.
* Existing vendor/business editors reuse the read-only membership interface with
  non-primary manager capability. No vendor ownership or ordinary staff membership
  is granted to agents.
* Agents can configure menus, service points, ordering hours/channels, documents
  and receiving-payment details. Live orders, reports, staff administration,
  Operations routes and approvals are blocked server-side.
* Disabling an agent revokes tokens. Every authenticated request also checks the
  active flag and current assignments. Removing an assignment takes effect even
  if a browser still displays its previous business list.

## Invitations and handover

The admin creates an agent and sends a single-use 48-hour password invitation.
Agents may invite a new or existing owner for vendor-wide assignments. Owner
membership stays non-primary/inactive until acceptance; existing primary owners
cannot be replaced through this workflow. New owners set their own passwords.

Onboarding stages: draft, in_progress, submitted, ready, handed_over. Agents may
submit progress. Operations marks it ready. Handover requires an accepted owner.
Agent access remains until Operations explicitly removes the assignment, allowing
an agreed support period. Administrative changes and successful setup requests
are recorded in `onboarding_audits`; password and document contents are not logged.

## Verification

```sh
./vendor/bin/pest
```

The test bootstrap forces SQLite `:memory:` and bypasses development caches;
TestCase refuses persistent databases. Agent regression tests cover portal
restrictions, assignments, immediate revocation, invitations, owner acceptance,
menu/service setup and handover. No development database reset is required.
