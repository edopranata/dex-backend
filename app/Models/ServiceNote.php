<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Free-text admin note for one entry of the third-party service directory.
 */
#[Fillable(['service', 'note', 'updated_by'])]
class ServiceNote extends Model {}
