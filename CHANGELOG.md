# Changelog

All notable changes to `laravel-revisions` will be documented in this file

## 3.0.1 - 2019/22/03

- Added support for excluding certain fields when creating a revision.

## 3.0.0 - 2019/22/03

- Support Laravel >= 5.8
   - breaking change: `bigInteger` column type on `users` table -> affects foreign key constraints on `users` table

## 2.0.0 - 2019/22/03

- Transferred ownership to the [Neurony](https://github.com/Neurony) organisation
- Changed package name in `composer.json` file from `zbiller/laravel-revisions` to `neurony/laravel-revisions`
- Changed namespace from `Zbiller\Revisions` to `Neurony\Revisions`
- Updated continuous integration badges to point to the newly transferred repository  

## 1.0.0 - 2019/2/15

- Initial release