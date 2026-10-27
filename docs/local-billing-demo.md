# Local hospitality billing demo

Created in the current local database on **27 October 2026 at 11:59, Africa/Dar_es_Salaam**.

- 10 new `[DEMO]` businesses, 100 orders each: **1,000 orders and 1,000 payment records**.
- 1,000 saved fee snapshots, including historical 1% and 2%, free periods, and zero commission.
- 22 initial invoices, verified synthetic invoice payments, a partial payment, and restriction/restoration history.
- Five payment methods copied by type from Wafa: M-Pesa Lipa Namba, Mixx, Cash, POS/Card and bank transfer. All receiving account numbers are clearly non-payment `DEMO-*` values.
- Five hotels (5-, 4- and 3-star examples), two cafés and three restaurants. No fabricated customer review ratings.
- 20 downloaded logo/photo assets, all served locally; the source URLs and checksums are in `database/fixtures/billing-demo.json`.
- Existing users, Wafa, orders, payments and billing records were checked by checksum and were unchanged.

## Open in your browser

- Discover: http://192.168.1.4:3002/discover — **11 businesses** including your original Wafa. Search `[DEMO]`, or use Hotels/Cafés/Restaurants.
- Operations scenarios: http://192.168.1.4:3001/business-billing/demo — open Business billing, then **Local billing demo**.
- Business Portal: http://192.168.1.4:3000/billing — sign in with the demo owner to switch among the ten businesses.

Use your usual Operations admin, or the new demo Operations account below. The IP is the current local address; if it changes, use the new address on the same ports.

## Demo accounts

All demo passwords: `PapersticDemo@2026!`

| Account | Email |
|---|---|
| Owner of all ten demo businesses | billing-demo-owner@paperstic.test |
| Demo Operations administrator | billing-demo-ops@paperstic.test |
| Business manager for business N | billing-demo-manager-N@paperstic.test |
| Waiter for business N | billing-demo-waiter-N@paperstic.test |
| Counter staff for business N | billing-demo-counter-N@paperstic.test |

Replace N with 1–10 according to the table below. These are local test accounts only; no real customer contact details were copied.

## Scenarios and dates

All times below are Dar es Salaam time. Orders generally span 1–27 October 2026; the seven-day-trial café's orders span 21–27 October. The newest initial order is 27 October at 10:59.

| N | Business | Scenario | Fees start | Next invoice at seed | Initial invoices | Ordering at seed |
|---|---|---|---|---|---:|---|
| 1 | [DEMO] Hyatt Regency The Kilimanjaro | Paid weekly invoice | 30 Sep 2026 11:59 | 03 Nov 2026 11:59 | 4 | Available |
| 2 | [DEMO] Johari Rotana | Overdue — ordering blocked | 30 Sep 2026 11:59 | 03 Nov 2026 11:59 | 4 | Blocked |
| 3 | [DEMO] Southern Sun Dar es Salaam | Partially paid overdue — ordering blocked | 30 Sep 2026 11:59 | 03 Nov 2026 11:59 | 4 | Blocked |
| 4 | [DEMO] Peacock Hotel | Invoice within grace period | 30 Sep 2026 11:59 | 03 Nov 2026 11:59 | 4 | Available |
| 5 | [DEMO] Dar es Salaam Serena Hotel | Monthly billing — next month | 30 Sep 2026 11:59 | 01 Nov 2026 09:00 | 0 | Available |
| 6 | [DEMO] Epi d’Or | Seven-day free period | 28 Oct 2026 11:59 | 27 Oct 2026 12:09 | 0 | Available |
| 7 | [DEMO] Karambezi Café | Fourteen-day trial ended | 15 Oct 2026 11:59 | 03 Nov 2026 11:59 | 2 | Available |
| 8 | [DEMO] Addis in Dar | Zero commission | 30 Sep 2026 11:59 | 27 Oct 2026 12:09 | 0 | Available |
| 9 | [DEMO] Wafa Restaurant | Rate changed — invoice generation due now | 30 Sep 2026 11:59 | 27 Oct 2026 11:58 | 0 | Available |
| 10 | [DEMO] The Palm Brasserie | Deadline extended — ordering restored | 30 Sep 2026 11:59 | 03 Nov 2026 11:59 | 4 | Available |

The latest overdue invoices for businesses 2 and 3 were sent **24 October at 07:59** and became due **27 October at 07:59**. Business 3 has only paid half of its latest invoice. Business 4's invoices were sent **26 October at 07:59**, due **29 October at 07:59**. Business 10's overdue deadline was extended to **30 October at 11:59**, preserving a recorded restriction/restoration episode.

Business 9 is ready to generate its first invoices on the next processing run. Its order snapshots retain 1% before **15 October at 11:59**, and 2% afterwards. Businesses 6 and 8 have a due schedule but no chargeable fees; processing should advance their schedule without producing a payment demand.

## Test the normal schedule

In the server directory:

```sh
php artisan schedule:work
```

The normal scheduler runs invoice processing every five minutes and restriction reconciliation every minute. It processes all eligible businesses according to their settings, as it normally would. It does not run continuously until you start a scheduler process.

For a run restricted to these ten demo businesses, use **Run demo billing now** on the Operations demo page, or:

```sh
php artisan billing:demo run
```

This uses the existing generation, delivery and restriction services. It records a result in **Demo run history**. That history is explicitly for demo runs, not a global scheduler execution log. Repeating a run must not duplicate invoices.

To inspect without changing records:

```sh
php artisan billing:demo status
```

## Browser test sequence

1. Confirm all ten demo listings appear on Discover, with venue photos, map locations, menu and ordering links.
2. Try a new guest/staff order on business 2 or 3. New ordering should be blocked. Existing order handling, reports, profiles and billing remain accessible.
3. In Operations, open business 3's outstanding invoice and record the remaining synthetic amount. Refresh the Business Portal; ordering should become available.
4. Process demo billing. Business 9 should receive numbered invoices with PDFs, sales/order counts and its historical commission rates. Repeat processing: invoice numbers and counts should not duplicate.
5. Check business 4: ordering remains available during grace. Check business 10: its extended deadline keeps ordering available.
6. Check business 6: all initial fees are zero. Create a new order after its fee start, or deliberately update its future fee start in the Operations billing settings to test sooner. Existing free-period snapshots must stay zero.
7. Check business 8: completed paid orders have 0% / TZS 0 fees; no positive invoice should be issued.
8. For monthly business 5, review 1 November at 09:00 as its next billing date. You can deliberately change its next invoice date using the existing Operations settings to test generation sooner.

The dataset uses the real application clock. No global virtual clock was added, because the current database also contains your original business.

## Email and files

Demo invoice notifications always use Laravel's **log mailer** when the application is local/testing, including when the normal scheduler dispatches them. No real venue email addresses or bank accounts are used. PDFs are available through the existing invoice download controls.

Assets are in `storage/app/public/billing-demo/`; the symbolic public/storage link already exists. To download them again on another local checkout:

```sh
python3 scripts/download-billing-demo-assets.py
```

The seeder is append-only and refuses to run again when this dataset exists. It does not reset, truncate, migrate or delete your current database. Its command is local/testing only:

```sh
php artisan billing:demo seed              # preview only
php artisan billing:demo seed --execute    # only when the dataset does not exist
```

## Isolated automated verification

```sh
./vendor/bin/pest --filter=LocalBillingDemo
```

The test bootstrap forces SQLite `:memory:` and refuses persistent databases. The production/development database is not used by tests.

## Venue references

Names, photos, logos and geographic references are for this local demo only. `[DEMO]` labels and profile descriptions clearly distinguish them from real partner registrations. Hotel star classes describe accommodation categories, not customer review scores. Photos and logos retain the rights of their respective owners.

- Hyatt Regency The Kilimanjaro: https://www.asbhospitality.co/portfolio/hyatt-regency-dar-es-salaam/
- Johari Rotana: https://www.rotana.com/rotanahotelandresorts/tanzania/daressalaam/joharirotana
- Southern Sun Dar es Salaam: https://www.southernsun.com/southern-sun-dar-es-salaam
- Peacock Hotel: https://peacock-hotel.com/
- Dar es Salaam Serena Hotel: https://www.serenahotels.com/dar-es-salaam
- Epi d’Or: https://epidor.co.tz/restaurant/
- Karambezi Café: https://www.karambezicafe.com/
- Addis in Dar: https://addisindar.com/
- Wafa Restaurant: https://darwafa.com/food-drinks/
- The Palm Brasserie: https://www.asbhospitality.co/portfolio/hyatt-regency-dar-es-salaam/

Hotel class reference: https://www.icao.int/sites/default/files/Meetings/AirLawTreatyWorkshopTanzania2025/Information%20to%20Participants/New-Information-Bulletin-January-2026.pdf
