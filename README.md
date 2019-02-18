 # Create revisions for any Eloquent model along with its relationships   

[![Build Status](https://travis-ci.org/zbiller/laravel-revisions.svg?branch=master)](https://travis-ci.org/zbiller/laravel-revisions)
[![StyleCI](https://github.styleci.io/repos/170915589/shield?branch=master)](https://github.styleci.io/repos/170915589)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/zbiller/laravel-revisions/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/zbiller/laravel-revisions/?branch=master)

- [Overview](#overview)   
- [Installation](#installation)   
- [Setup](#setup)
- [Usage](#usage)   
- [Customisations](#customisations)   
- [Events](#events)   

# Overview

This package allows you to create revisions for any Eloquent model record along with its underlying relationships.    
   
* When a revision is created, it gets stored inside the `revisions` database table.    
* Revisions are created automatically on model update, using the `updated` Eloquent event
* Revisions can also can be created manually by using the `saveAsRevision()`   
* When a record is force deleted, all its revisions will also be removed automatically, using the `deleted` Eloquent event   

As already mentioned, this package is capable of revisioning entire relationships alongside the model record.   
   
**The cool part is that it's also capable of re-creating the relationships records from ground up, if they were force deleted along the way, during the lifetime of that model record.** 
   
Relationship types that can be revisioned: `hasOne`, `morphOne`, `hasMany`, `morphMany`, `belongsToMany`, `morphToMany`

# Installation

Install the package via Composer:

```
composer require zbiller/laravel-revisions
```

Publish the config file with:

```
php artisan vendor:publish --provider="Zbiller\Revisions\ServiceProvider" --tag="config"
```

Publish the migration file with:

```
php artisan vendor:publish --provider="Zbiller\Revisions\ServiceProvider" --tag="migrations"
```

After the migration has been published you can create the `revisions` table by running:

```
php artisan migrate
```

# Setup

### Step 1

Your Eloquent models should use the `Zbiller\Revisions\Traits\HasRevisions` trait and the `Zbiller\Revisions\Options\RevisionOptions` class.   

The trait contains an abstract method `getRevisionOptions()` that you must implement yourself.   

Here's an example of how to implement the trait:   

```php
<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Zbiller\Revisions\Options\RevisionOptions;
use Zbiller\Revisions\Traits\HasRevisions;

class YourModel extends Model
{
    use HasRevisions;

    /**
     * Get the options for revisioning the model.
     *
     * @return RevisionOptions
     */
    public function getRevisionOptions(): RevisionOptions
    {
        return RevisionOptions::instance();
    }
}
```

### Step 2

Inside the `revisions.php` config file, write the full namespace of your `User` model class for the `user_model` config key.   
   
By default, this value is the FQN of Laravel's `User` model class (`\App\User`). You can also leave this `NULL` if your application doesn't have the concept of users.   
   
This bit is used by the `Zbiller\Revisions\Traits\HasRevisions` trait to know who created which revisions.

# Usage

### Fetch revisions

You can fetch a model record's revisions by using the `revisions()` morph to many relation present on the `Zbiller\Revisions\Traits\HasRevisions` trait.

```php
$model = YourModel::find($id);

$revisions = $model->revisions;
```

### Create revisions (automatically)

Once you've used the `Zbiller\Revisions\Traits\HasRevisions` trait in your Eloquent models, each time you update a model record, a revision containing its original attribute values will be created automatically using the `updated` Eloquent event: 

```php
// model is state 1
$model = YourModel::find($id);

// model is state 2
// a revision containing the model's state 1 is created 
$model->update(...);
```

Alternatively, you can also store a revision each time you `create` a new model record, by using the `created` Eloquent event   
(see [Customisations](#customisations))

### Create revisions (manually)

If you ever need it, you can also create a revision manually, by using the `saveAsRevision()` method from the `Zbiller\Revisions\Traits\HasRevisions` trait:

```php
$model = YourModel::find($id);

// a new entry is stored inside the 'revisions' database table
// reflecting the current state of that model record
$model->saveAsRevision();
```

### Rollback to a past revision

You can rollback the model record to one of its past revisions by using the `rollbackToRevision()` method.

```php
// model is state 1
$model = YourModel::find($id);
$revision = $model->revisions()->latest()->first();

// model is now in state 0
$model->rollbackToRevision($revision);
```

# Customisations

### Enable revisioning on create

By default, when creating a new model record, a revision will not be created, because the record is fresh and it's at its first state. However, if you wish to create a revision when creating the model record, you can do so by using the `enableRevisionOnCreate()` method in your definition of the `getRevisionOptions()` method.

```php
/**
 * Get the options for revisioning the model.
 *
 * @return RevisionOptions
 */
public function getRevisionOptions(): RevisionOptions
{
    return RevisionOptions::instance()
        ->enableRevisionOnCreate();
}
```

### Limit the revisions

You can limit the number of revisions each model record can have by using the `limitRevisionsTo()` method in your definition of the `getRevisionOptions()` method.   
   
This prevents ending up with thousands of revisions for a heavily updated record.   
   
When the limit is reached, after creating the new (latest) revision, the script automatically removes the oldest revision that model record has.

```php
/**
 * Get the options for revisioning the model.
 *
 * @return RevisionOptions
 */
public function getRevisionOptions(): RevisionOptions
{
    return RevisionOptions::instance()
        ->limitRevisionsTo(100);
}
```

### Revision only certain fields

If you don't want to revision all the model's fields (attributes), you can manually specify which fields you wish to store when creating a new revision, by using the `fieldsToRevision()` method in your definition of the `getRevisionOptions()` method.   
   
Please note that the fields omitted won't be stored when creating the revision, but when rolling back to a revision, those ignored fields will become null / empty for the actual model record.

```php
/**
 * Get the options for revisioning the model.
 *
 * @return RevisionOptions
 */
public function getRevisionOptions(): RevisionOptions
{
    return RevisionOptions::instance()
        ->fieldsToRevision('title', 'content');
}
```

### Revision relationships alongside the model record

More often than not you will want to create a full copy in time of the model record and this includes revisioning its relations too (especially child relations).   
   
You can specify what relations to be revisioned alongside the model record by using the `relationsToRevision()` method in your definition of the `getRevisionOptions()` method.   
   
Please note that when rolling back the model record to a past revision, the specified relations will also be rolled back to their state when that revision happened (this includes re-creating a relation record from ground up, if it was force deleted along the way).

```php
/**
 * Get the options for revisioning the model.
 *
 * @return RevisionOptions
 */
public function getRevisionOptions(): RevisionOptions
{
    return RevisionOptions::instance()
        ->relationsToRevision('comments', 'author');
}
```

### Disable creating a revision when rolling back

By default, when rolling back to a past revision, a new revision is automatically created. This new revision contains the model record's state before the rollback happened.   
   
You can disable this behavior by using the `disableRevisioningWhenRollingBack()` method in your definition of the `getRevisionOptions()` method.   

```php
/**
 * Get the options for revisioning the model.
 *
 * @return RevisionOptions
 */
public function getRevisionOptions(): RevisionOptions
{
    return RevisionOptions::instance()
        ->disableRevisioningWhenRollingBack();
}
```

# Events

The revision functionality comes packed with two Eloquent events: `revisioning` and `revisioned`   
   
You can implement these events in your Eloquent models as you would implement any other Eloquent events that come with the Laravel framework.

```php
<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Zbiller\Revisions\Options\RevisionOptions;
use Zbiller\Revisions\Traits\HasRevisions;

class YourModel extends Model
{
    use HasRevisions;

    /**
     * Boot the model.
     *
     * @return RevisionOptions
     */
    public static function boot()
    {
        parent::boot();

        static::revisioning(function ($model) {
            // your logic here
        });

        static::revisioned(function ($model) {
            // your logic here
        });
    }
    
    /**
     * Get the options for revisioning the model.
     *
     * @return RevisionOptions
     */
    public function getRevisionOptions(): RevisionOptions
    {
        return RevisionOptions::instance();
    }
}
```

# Security

If you discover any security related issues, please email zbiller@gmail.com instead of using the issue tracker.

# License

The MIT License (MIT). Please see [LICENSE](LICENSE.md) for more information.

# Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

# Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.