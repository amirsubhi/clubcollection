<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One portal-wide setting (key / value); read and written through App\Support\Portal\PortalSettings. */
class PortalSetting extends Model
{
    protected $fillable = ['key', 'value'];
}
