<?php

/*
 * This file is part of the Kimai time-tracking app.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Controller;

use App\Entity\Role;
use App\Entity\RolePermission;
use App\Form\RoleType;
use App\Repository\RolePermissionRepository;
use App\Repository\RoleRepository;
use App\Security\RolePermissionManager;
use App\Security\RoleService;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Controller used to manage user roles and role permissions.
 *
 * @Route(path="/admin/permissions")
 * @Security("is_granted('role_permissions')")
 */
final class PermissionController extends AbstractController
{
    /**
     * @var RoleService
     */
    private $roleService;
    /**
     * @var RolePermissionManager
     */
    private $manager;
    /**
     * @var RoleRepository
     */
    private $roleRepository;

    public function __construct(RoleService $roleService, RolePermissionManager $manager, RoleRepository $roleRepository)
    {
        $this->roleService = $roleService;
        $this->manager = $manager;
        $this->roleRepository = $roleRepository;
    }

    /**
     * @Route(path="", name="admin_user_permissions", methods={"GET", "POST"})
     * @Security("is_granted('role_permissions')")
     */
    public function permissions()
    {
        $all = $this->roleRepository->findAll();
        $existing = [];

        foreach ($all as $role) {
            $existing[] = $role->getName();
        }

        $existing = array_map('strtoupper', $existing);

        // automatically import all hard coded (default) roles into the database table
        foreach ($this->roleService->getAvailableNames() as $roleName) {
            $roleName = strtoupper($roleName);
            if (!in_array($roleName, $existing)) {
                $role = new Role();
                $role->setName($roleName);
                $this->roleRepository->saveRole($role);
                $existing[] = $roleName;
            }
        }

        // be careful, the order of the search keys is important!
        $permissionOrder = [
            'Audit' => 'audit_',
            'User' => '_user',
            'User profile (own)' => '_own_profile',
            'User profile (other)' => '_other_profile',
            'Customer (Teamlead)' => '_teamlead_customer',
            'Customer (Team member)' => '_team_customer',
            'Customer (Admin)' => '_customer',
            'Project (Teamlead)' => '_teamlead_project',
            'Project (Team member)' => '_team_project',
            'Project (Admin)' => '_project',
            'Activity' => '_activity',
            'Timesheet (own)' => '_own_timesheet',
            'Timesheet (other)' => '_other_timesheet',
            'Invoice' => '_invoice',
            'Teams' => '_team',
            'Tags' => '_tag',
            'Expense' => '_expense',
            'Task' => 'task_',
        ];
        $permissionSorted = [];
        $other = [];

        foreach ($permissionOrder as $title => $search) {
            $permissionSorted[$title] = [];
        }

        foreach ($this->manager->getPermissions() as $permission) {
            $found = false;

            foreach ($permissionOrder as $title => $search) {
                if (strpos($permission, $search) !== false) {
                    $permissionSorted[$title][] = $permission;
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $other[] = $permission;
            }
        }

        ksort($permissionSorted);

        $permissionSorted['Other'] = $other;

        // order the roles from most powerful to least powerful, custom roles at the end
        $roles = [
            'ROLE_SUPER_ADMIN' => null,
            'ROLE_ADMIN' => null,
            'ROLE_TEAMLEAD' => null,
            'ROLE_USER' => null,
        ];
        foreach ($this->roleRepository->findAll() as $role) {
            $roles[$role->getName()] = $role;
        }

        return $this->render('user/permissions.html.twig', [
            'roles' => array_values($roles),
            'permissions' => $this->manager->getPermissions(),
            'sorted' => $permissionSorted,
            'manager' => $this->manager,
            'system_roles' => $this->roleService->getSystemRoles(),
        ]);
    }

    /**
     * @Route(path="/roles/create", name="admin_user_roles", methods={"GET", "POST"})
     * @Security("is_granted('role_permissions')")
     */
    public function createRole(Request $request): Response
    {
        $role = new Role();

        $form = $this->createForm(RoleType::class, $role, [
            'action' => $this->generateUrl('admin_user_roles', []),
            'method' => 'POST',
        ]);

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $this->roleRepository->saveRole($role);
                $this->flashSuccess('action.update.success');
            } catch (\Exception $ex) {
                $this->flashSuccess('action.update.error');
            }

            return $this->redirectToRoute('admin_user_permissions');
        }

        return $this->render('user/edit_role.html.twig', [
            'form' => $form->createView(),
            'role' => $role,
        ]);
    }

    /**
     * @Route(path="/roles/{id}/delete", name="admin_user_role_delete", methods={"GET", "POST"})
     * @Security("is_granted('role_permissions')")
     */
    public function deleteRole(Role $role): Response
    {
        try {
            $this->roleRepository->deleteRole($role);
            $this->flashSuccess('action.delete.success');
        } catch (\Exception $ex) {
            $this->flashError('action.delete.error');
        }

        return $this->redirectToRoute('admin_user_permissions');
    }

    /**
     * @Route(path="/roles/{id}/{name}/{value}", name="admin_user_permission_save", methods={"GET"})
     * @Security("is_granted('role_permissions')")
     */
    public function savePermission(Role $role, string $name, string $value, RolePermissionRepository $rolePermissionRepository): Response
    {
        if (!$this->manager->isRegisteredPermission($name)) {
            throw $this->createNotFoundException('Unknown permission: ' . $name);
        }

        try {
            $permission = $rolePermissionRepository->findRolePermission($role, $name);
            if (null === $permission) {
                $permission = new RolePermission();
                $permission->setRole($role);
                $permission->setPermission($name);
            }
            $permission->setAllowed((bool) $value);

            $rolePermissionRepository->saveRolePermission($permission);
            $this->flashSuccess('action.update.success');
        } catch (\Exception $ex) {
            $this->flashError('action.update.error');
        }

        return $this->redirectToRoute('admin_user_permissions');
    }
}
