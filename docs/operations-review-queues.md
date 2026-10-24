# Operations approval queues and alerts

`/kyc` lists one row per vendor or business. Default pending work means the information review is pending (including historical records without a review row), or the entity has a document in `pending`, `in_review` or `needs_review`. Returned documents are `needs_update`; they are awaiting the applicant and do not count as actionable work until resubmitted. Information approval and document approval are separate decisions. Neither changes business enablement or overrides the existing payment and compliance activation rules.

## Local rollout

The feature uses the existing Laravel database notifications table and adds only `operations_review_profiles` and `operations_review_history`:

```sh
php artisan migrate --path=database/migrations/2026_10_24_000002_create_operations_review_profiles.php
php artisan route:clear
```

Do not reset or reseed any database. Existing document decisions, vendor information and business activation values remain intact. Historical profiles start as unreviewed; the first eligible Operations user visit creates one initial summary notification if that user has no review notifications. It does not manufacture every historical event.

## Access and decisions

The API requires an active `paperstic` user with `operations.kyc.view`. Information and document decisions additionally require `operations.kyc.review` plus `operations.kyc.approve` or `operations.kyc.return`. Existing KYC permissions cover both vendor and business compliance review; no new roles or automatic grants are introduced. Agents and Business portal users cannot access these APIs even if a KYC permission is mistakenly assigned.

Dedicated review pages live at `/kyc/vendor/{uuid}` and `/kyc/business/{uuid}`. Document previews are private authenticated downloads. New review decisions require a revision token; stale submissions return HTTP 409 and the page reloads the latest record. Information review history and document decisions from both the new workspace and existing Business detail review controls share the history table. Historical document decisions remain visible on their documents.

The document checklist uses the same `ComplianceDocumentRequirements` service as Business portal uploads, so country/type-specific rules stay consistent. Relevant information edits reopen approved/returned information reviews. Document submissions reopen returned profile work and create document work, while an already approved information decision stays approved.

## Notifications

Model observers cover vendor/business creation and document upload/resubmission. Notifications are inserted after transaction commit for eligible Operations users, with deterministic identifiers preventing duplicate delivery for one event. Notifications never send email. Read state belongs to each user and does not resolve a queue item. The common Operations header refreshes alerts every 30 seconds and on focus/visibility changes; local review decisions refresh alerts immediately. A notification links to the current entity review, including when the original work has already been resolved.

The legacy business document register remains available at `/kyc/documents`.

## Validation

Run `./vendor/bin/pest` from `server`. The testing bootstrap enforces SQLite `:memory:` and bypasses cached development configuration. `OperationsReviewQueueTest` covers queue isolation, server filtering/pagination, correction/resubmission, stale decisions, private previews, per-user notification reads, initial summary deduplication, shared document rules and preservation of VAT/activation behavior. Frontend checks are TypeScript and targeted ESLint from `operations`.
