# Contributing to Customer documents

Thanks for helping. This add-on follows [ISPGrid's contributing guide](https://github.com/ispgrid/ispgrid/blob/master/CONTRIBUTING.md)
(its "Write an add-on" section covers what an add-on may and may not do) and its
[Code of Conduct](https://github.com/ispgrid/ispgrid/blob/master/CODE_OF_CONDUCT.md).

- Work on a branch from `master` (no `/` in branch names) and keep commits small with a short imperative subject.
- Run the tests in an ISPGrid checkout as described in the README's "Development" section, and run ISPGrid's
  `vendor/bin/pint` on the files you changed.
- Every UI string goes through `__()` and needs a Hindi entry in `lang/hi.json`.
- Never write FreeRADIUS's `rad*` tables, never drop or empty `ispgrid_documents` in a migration, and never remove a
  customer's documents except through Erase: ISPs must keep them.

Questions go to [ISPGrid's Discussions](https://github.com/ispgrid/ispgrid/discussions), bugs to this repository's
Issues, and security problems to private reporting (Security, then Report a vulnerability).
