<?php

namespace App\Models;

use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    /**
     * Every permission staff can be given, grouped for the role editor.
     *
     * @var array<string, array<string, string>>
     */
    public const PERMISSIONS = [
        'Clients' => [
            'clients.view' => 'View clients',
            'clients.manage' => 'Create and edit clients',
        ],
        'Billing' => [
            'orders.manage' => 'Manage orders',
            'services.manage' => 'Manage services and run module actions',
            'domains.manage' => 'Manage domains and registrar actions',
            'billing.view' => 'View invoices and payments',
            'billing.manage' => 'Create invoices and record payments',
            'coupons.manage' => 'Manage coupons',
        ],
        'Support' => [
            'support.manage' => 'Answer tickets',
        ],
        'Setup' => [
            'products.manage' => 'Manage products and servers',
            'settings.manage' => 'Change system settings, gateways and email templates',
            'staff.manage' => 'Manage staff and roles',
            'system.update' => 'Install updates',
            'marketplace.manage' => 'Install themes and extensions from the marketplace',
        ],
    ];

    protected $fillable = ['name', 'permissions'];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
        ];
    }

    /**
     * @return HasMany<Admin, $this>
     */
    public function admins(): HasMany
    {
        return $this->hasMany(Admin::class);
    }

    public function isOwner(): bool
    {
        return in_array('*', $this->permissions ?? [], true);
    }

    public function allows(string $permission): bool
    {
        $permissions = $this->permissions ?? [];

        if (in_array('*', $permissions, true) || in_array($permission, $permissions, true)) {
            return true;
        }

        // "billing.manage" implies "billing.view", and so on for every group.
        [$group, $ability] = array_pad(explode('.', $permission, 2), 2, null);

        return $ability === 'view' && in_array($group.'.manage', $permissions, true);
    }

    /**
     * @return list<string>
     */
    public static function allPermissionKeys(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::PERMISSIONS)));
    }
}
