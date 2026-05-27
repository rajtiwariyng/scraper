<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ExportHistory extends Model
{
    protected $fillable = [
        'admin_id','module','file_name','file_path','status','error'
    ];

    public function admin()
    {
        return $this->belongsTo(Admin::class);
    }
}
