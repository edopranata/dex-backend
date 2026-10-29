<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A runtime override of a config value, edited from the admin panel.
 * Secret values are stored encrypted by SettingsStore.
 */
#[Fillable(['key', 'value', 'updated_by'])]
class Setting extends Model {}
