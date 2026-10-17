# Customer documents for ISPGrid

An add-on for [ISPGrid](https://github.com/ispgrid/ispgrid) that keeps each customer's documents: ID proofs, a
photo, a signed agreement. Staff photograph a card with the phone's camera or upload a PDF while they create the
customer. Indian ISPs must keep ID proof for every customer; ISPs elsewhere can keep whatever they need.

> [!WARNING]
> **Preview: not ready for production yet.** Like ISPGrid itself, it can bring breaking changes until v1.0.0. Read the
> release notes before you upgrade.

## What it does

- **Document types you write:** on System, then Add-ons, add the types your ISP keeps (nothing is prefilled): a
  name, required or not, a help text for staff (for example "Upload a masked Aadhaar"), and whether to keep an ID
  number: none, the full number, or only the last 4 characters. One file per type per customer.
- **Documents tab in the customer form:** for each type, **Take photo** (opens the camera on a phone) or **Choose
  file**. JPG, PNG or PDF, up to 10 MB, checked by the file's content. A missing required document does not stop the
  save: the tab shows an amber dot and the customer appears under Missing documents.
- **Documents card on the customer page:** view a document, upload a missing one, edit its type or number, delete
  it, or download all of a customer's documents as one zip (for an audit). ID numbers are shown masked
  (`xxxx xxxx 4821`); **Show** reveals the full number.
- **Nothing is lost by accident:** Delete hides a document; **Deleted documents** (menu Add-ons) restores it or
  erases it for good. Replacing a file hides the old one the same way. Documents stay when a customer is deleted.
- **Missing documents** (menu Add-ons): customers without a required document.
- **Everything is on record:** every view, Show, download, upload, change, delete, restore, erase and export is
  written to the Activity log (never the number or the file).
- **Permissions** on the Roles screen, none given to a role on install (Admin has them all):
  "View customer documents", "Upload documents", "Edit and delete documents", "Restore deleted documents", "Erase
  deleted documents" and "Document types". Ticking one ticks what it needs (Erase ticks Restore, which ticks View).
- English and Hindi.

Not yet: blocking a customer's activation until the required documents are in, shrinking photos on the phone.

## Aadhaar and ID numbers

- **Full number or last 4:** the choice is yours, per type. A full number is stored encrypted and shown masked.
  UIDAI's Aadhaar Data Vault rule (a separate vault for Aadhaar numbers) applies to bodies that check Aadhaar online
  with UIDAI ("requesting entities", the ones doing Aadhaar e-KYC), as UIDAI's 2025 circular is summarised by law
  firms; an ISP that keeps a photo of the card and types the number is not one. If your ISP becomes one, switch the
  type to "Last 4 characters only": the panel then cuts every stored number of that type to its last 4 (it asks
  first; this cannot be undone). Check the current rules with your adviser.
- **Masked Aadhaar:** where you can, ask customers for a masked Aadhaar (first 8 digits hidden) and say so in the
  type's help text.
- **Duplicates:** a full number already on another customer gives a warning (compared on letters and digits,
  ignoring case), never a block.

## Encryption, backups and erasing

- Files and full ID numbers are encrypted with the panel's `APP_KEY` (in `/opt/ispgrid/.env`). Without that key they
  cannot be read. **Never change `APP_KEY` by hand.** For a planned change, keep the old key in `APP_PREVIOUS_KEYS`.
- The nightly ISPGrid backup holds the documents, the database and `.env` with the key, so restoring a backup (also
  on a new server) brings everything back readable. A stolen backup holds the key too: keep backups safe.
- **Erase for good** removes the file at once, but backups taken before still hold it until they age out (14 days).
- While staff pick a file, Livewire keeps it unencrypted in its temporary folder; the add-on deletes it as soon as
  the document is saved. A file picked but never saved stays there until Livewire's cleanup (about a day).

## Taking documents out

- **One customer, for an audit:** Download all on the customer's Documents card (one zip, logged).
- **Everything, for leaving ISPGrid:** on the server,

  ```bash
  cd /opt/ispgrid && sudo -u ispgrid php artisan documents:export /tmp/documents-export
  ```

  writes readable copies, one folder per customer, and `documents.csv` (customer, type, ID number as stored, who
  uploaded it and when). `--customer=<username>` exports one customer, `--include-deleted` adds hidden documents.
  The folder must be new or empty, and the files in it are readable: keep it safe and delete it when done.
- **Moving to another ISPGrid server:** no export needed. Restore the backup; it carries the key.

## Install

It needs ISPGrid 0.1.2 or newer (on an older ISPGrid it only adds its permissions and keeps its data). On a server
set up with the ISPGrid installer, as root:

```bash
sudo /opt/ispgrid/deploy/install.sh --addon=ispgrid/documents
```

The installer keeps the add-on on every upgrade, and the nightly backup keeps its database `ispgrid_documents` and
its files (`storage/app/private/addons/documents`).

## Remove

```bash
sudo /opt/ispgrid/deploy/install.sh --remove-addon=ispgrid/documents
```

The tab, card and pages go away, but the documents are kept: installing the add-on again shows them as they were. To
delete every document too:

```bash
sudo /opt/ispgrid/deploy/install.sh --remove-addon=ispgrid/documents --delete-data
```

The backup taken at the start of that run keeps a copy for 14 days; after that the documents cannot be brought back.

## Development

In an ISPGrid checkout:

```bash
composer config repositories.ispgrid-documents '{"type": "path", "url": "../path/to/ispgrid-documents", "options": {"symlink": true}}'
composer require ispgrid/documents:@dev
ISPGRID_ADDONS=on php vendor/bin/pest --test-directory=vendor/ispgrid/documents/tests vendor/ispgrid/documents/tests
```

Do not commit the changed `composer.json` and `composer.lock` in the ISPGrid checkout. The tests use the database
`ispgrid_documents_test` next to ISPGrid's `radius_test`.

## License

MIT
