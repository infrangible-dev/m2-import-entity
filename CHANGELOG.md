# Changelog

## 2.0.0

### Breaking changes

- Requires Magento 2.4.6 or newer and PHP 8.2 or newer. Older versions of Magento and PHP 7.1 to 8.1 are no longer
  supported; use version 1.x for these.
- Requires `infrangible/m2-import` 2.0, `infrangible/m2-core` 5.0, `infrangible/m2-foundation` 3.0 and
  `fewedev/base` 2.4.3. The changes of Import 2.0 apply, for example the return types of its abstract methods.
- All classes have native types and use constructor property promotion. The abstract methods of `Model\Entity`,
  `Model\Associated`, `Model\Extended`, `Model\Prepare`, `Model\Replace` and `Model\SpecialAttribute` declare their
  parameter and return types, so routines and models that extend them have to declare them too. The constructors keep
  their parameters.
- `Entity::prepareAttribute()` takes `?int $forceStoreId` instead of an implicitly nullable `int`.

### Fixed

- Elements whose store was given by their website (`website_id` or `website`), or whose `store_id` was a text like
  `"1"`, stopped the import with a TypeError and left a database transaction open. They are now imported in the
  default store of the website, or in the store; an unknown website makes the element invalid.
- `website_id` and `website` were validated as attributes, so such elements were invalid ("Unknown attribute"); they
  only define the store now.
- Associated data given as text separated by `|` failed with "could not be converted to string"; it is split now.

### Added

- Unit tests.
- README with the steps of an import and the kinds of values.

### Changed

- The import of a chunk, the validation of Related models and the preparation of Replace, Associated and
  SpecialAttribute models are split into methods; models of a class of the wrong kind make the element invalid
  instead of failing.
- Checks with PHPStan level 9, the Magento coding standard and unit tests on every push.
