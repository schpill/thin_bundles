<?php
    /**
     * Thin is a swift Framework for PHP 5.4+
     *
     * @package    Thin
     * @version    1.0
     * @author     Gerald Plusquellec
     * @license    BSD License
     * @copyright  1996 - 2015 Gerald Plusquellec
     * @link       http://github.com/schpill/thin
     */

    namespace Auth;

    use Thin\File;
    use Thin\Config;
    use Thin\Exception;
    use Thin\Container;
    use Thin\Instance;
    use Thin\Inflector;
    use Thin\Database\Collection;
    use Thin\Arrays;

    class Auth
    {
        private $userDb, $permissionDb, $roleDb, $user, $permission, $role, $userPermissions, $userRoles;

        public function __construct($user = null)
        {
            $this->config();
            $user = is_null($user) ? session(Config::get('bundle.auth.session', 'auth'))->getUser() : $user;

            $this->userDb       = jdb(Config::get('bundle.auth.database', 'auth'), Config::get('bundle.auth.table.user', 'user'));
            $this->permissionDb = jdb(Config::get('bundle.auth.database', 'auth'), Config::get('bundle.auth.table.permission', 'permission'));
            $this->roleDb       = jdb(Config::get('bundle.auth.database', 'auth'), Config::get('bundle.auth.table.role', 'role'));

            if (Arrays::is($user)) {
                $id = isAke($user, 'id', null);

                if (!is_null($id)) {
                    $user = $this->userDb->find($id);
                }
            }

            if (filter_var($user, FILTER_VALIDATE_INT) !== false) {
                $this->user = $this->userDb->find($user);
                $this->userRoles();
                $this->userPermissions();
            } elseif ($user instanceof Container) {
                $this->user = $user;
                $this->userRoles();
                $this->userPermissions();
            }
        }

        public static function instance($user = null)
        {
            $args   = func_get_args();
            $key    = sha1(serialize($args));
            $has    = Instance::has('Auth', $key);

            if (true === $has) {
                return Instance::get('Auth', $key);
            } else {
                return Instance::make('Auth', $key, new self($user));
            }
        }

        public function register(array $user)
        {
            $username   = isAke($user, 'username', null);
            $password   = isAke($user, 'password', null);
            $email      = isAke($user, 'email', null);
            $name       = isAke($user, 'name', null);
            $firstname  = isAke($user, 'firstname', null);

            $required = ['username', 'password', 'email', 'name', 'firstname'];

            foreach ($required as $field) {
                if (is_null($$field)) {
                    throw new Exception("Field $field is required to add user.");
                }
            }

            $user['password'] = sha1($password);
            $role = $this->addRole(['name' => $username]);
            $user = $this->userDb->create($user)->save();
            $this->addUserRole($role, $user);

            return $user;
        }

        public function unregister($user = null)
        {
            $user = is_null($user) ? $this->user : $user;

            if (is_null($user) || !$user instanceof Container) {
                throw new Exception("User has a wrong format.");
            } else {
                $user->delete();
                $this->removeRole($this->getRole());

                return $this;
            }
        }

        public function addRole(array $role)
        {
            $name       = isAke($role, 'name', null);
            $required   = ['name'];

            foreach ($required as $field) {
                if (is_null($$field)) {
                    throw new Exception("Field $field is required to add role.");
                }
            }

            return $this->roleDb->create($role)->save();
        }

        public function addPermission(array $permission)
        {
            $name       = isAke($permission, 'name', null);
            $required   = ['name'];

            foreach ($required as $field) {
                if (is_null($$field)) {
                    throw new Exception("Field $field is required to add permission.");
                }
            }

            return $this->permissionDb->create($permission)->save();
        }

        public function removeUserById($id)
        {
            if (filter_var($id, FILTER_VALIDATE_INT) !== false) {
                $user = $this->userDb->find($id);

                if (!is_null($user)) {
                    return $this->removeUser($user);
                } else {
                    throw new Exception("User does not exist.");
                }
            } else {
                throw new Exception("id $id is not an integer.");
            }
        }

        public function removeRole($role)
        {
            if (is_null($role) || !$role instanceof Container) {
                throw new Exception("Role has a wrong format.");
            } else {

                $db = jdb(Config::get('bundle.auth.database', 'auth'), Config::get('bundle.auth.table.userrole', 'userrole'));
                $userroles = $db->where(['role_id', '=', $role->id])->where(['user_id', '=', $this->user->id])->first(true);

                if ($userroles) {
                    $userroles->delete();dd($userroles);
                }
            }

            return $this;
        }

        public function removeRoleById($id)
        {
            if (filter_var($id, FILTER_VALIDATE_INT) !== false) {
                $role = $this->roleDb->find($id);

                if (!is_null($role)) {
                    return $this->removeRole($role);
                } else {
                    throw new Exception("Role does not exist.");
                }
            } else {
                throw new Exception("id $id is not an integer.");
            }
        }

        public function removePermission($permission)
        {
            if (is_null($permission) || !$permission instanceof Container) {
                throw new Exception("Permission has a wrong format.");
            } else {
                $db = jdb(
                    Config::get('bundle.auth.database', 'auth'),
                    Config::get('bundle.auth.table.rolepermission', 'rolepermission')
                );

                $rolepermissions = $db->where(['permission_id', '=', $permission->id])->exec(true);

                if ($rolepermissions->count() > 0) {
                    $rolepermissions->delete();
                }

                $permission->delete();
            }

            $this->userRoles(true);
            $this->userPermissions(true);

            return $this;
        }

        public function removePermissionById($id)
        {
            if (filter_var($id, FILTER_VALIDATE_INT) !== false) {
                $permission = $this->permissionDb->find($id);

                if (!is_null($permission)) {
                    return $this->removePermission($permission);
                } else {
                    throw new Exception("Permission does not exist.");
                }
            } else {
                throw new Exception("id $id is not an integer.");
            }
        }

        public function addUserRole($role, $user = null)
        {
            $db = jdb(Config::get('bundle.auth.database', 'auth'), Config::get('bundle.auth.table.userrole', 'userrole'));

            $user = is_null($user) ? $this->user : $user;

            if (is_null($user) || !$user instanceof Container) {
                throw new Exception("User has a wrong format.");
            } else {
                if (is_null($role) || !$role instanceof Container) {
                    throw new Exception("Role has a wrong format.");
                } else {
                    return $db->create(
                        array(
                            'user_id' => 'relation',
                            'role_id' => 'relation'
                        )
                    )->linkUser($user)
                    ->linkRole($role)
                    ->save();
                }
            }
        }

        public function makeAdmin()
        {
            $role = $this->roleDb->where(['name', '=', 'admin'])->first(true);

            return $this->addUserRole($role, $this->user);
        }

        public function unmakeAdmin()
        {
            $role = $this->roleDb->where(['name', '=', 'admin'])->first(true);

            $this->removeRole($role, $this->user);
        }

        public function addRoleByName($role = 'admin')
        {
            return $this->addRole(array('name' => Inflector::lower($role)));
        }

        public function addPermissionByName($permission = 'admin')
        {
            return $this->addPermission(array('name' => Inflector::lower($permission)));
        }

        public function addRolePermission($role, $permission)
        {
            $db = jdb(Config::get('bundle.auth.database', 'auth'), Config::get('bundle.auth.table.rolepermission', 'rolepermission'));

            if (is_null($permission) || !$permission instanceof Container) {
                throw new Exception("Permission has a wrong format.");
            } else {
                if (is_null($role) || !$role instanceof Container) {
                    throw new Exception("Role has a wrong format.");
                } else {
                    return $db->create(
                        array(
                            'permission_id' => 'relation',
                            'role_id' => 'relation'
                        )
                    )->linkPermission($permission)
                    ->linkRole($role)
                    ->save();
                }
            }

            return $this;
        }

        public function guest()
        {
            return !$this->user();
        }

        public function user()
        {
            return isset($this->user) ? $this->user : false;
        }


        public function userRoles($force = false)
        {
            if (isset($this->userRoles) && false === $force) {
                return $this->userRoles;
            }

            if (isset($this->user)) {
                $this->userRoles = $this->roles($this->user);
            }

            return $this;
        }

        public function userPermissions($force = false)
        {
            if (isset($this->userPermissions) && false === $force) {
                return $this->userPermissions;
            }

            if (isset($this->user)) {
                $roles = $this->roles($this->user);
                $permissions = [];

                if ($roles instanceof Collection) {
                    foreach ($roles->rows() as $role) {
                        $rolesPermissions = $this->permissions($role->role());
                        array_push($permissions, $rolesPermissions);
                    }
                }

                $this->userPermissions = $permissions;
            }

            return $this;
        }

        public function roles($user = null)
        {
            $db = jdb(Config::get('bundle.auth.database', 'auth'), Config::get('bundle.auth.table.userrole', 'userrole'));

            if (is_null($user)) {
                return $db->fetch()->exec(true);
            } else {
                if ($user instanceof Container) {
                    return $db->where(['user_id', '=', $user->id])->exec(true);
                } elseif (filter_var($user, FILTER_VALIDATE_INT) !== false) {
                    return $db->where(['user_id', '=', $user])->exec(true);
                } else {
                    throw new Exception("User has a wrong format.");
                }
            }
        }

        public function permission($name)
        {
            return $this->permissionDb->name($name)->first(true);
        }

        public function permissions($role = null)
        {
            $db = jdb(Config::get('bundle.auth.database', 'auth'), Config::get('bundle.auth.table.rolepermission', 'rolepermission'));

            if (is_null($role)) {
                return $db->fetch()->exec(true);
            } else {
                if ($role instanceof Container) {
                    return $db->where(['role_id', '=', $role->id])->exec(true);
                } elseif (filter_var($role, FILTER_VALIDATE_INT) !== false) {
                    return $db->where(['role_id', '=', $role])->exec(true);
                } else {
                    throw new Exception("Role has a wrong format.");
                }
            }
        }

        public function login($username, $password, $isSha1 = false)
        {
            $session    = session(Config::get('bundle.auth.session', 'auth'));
            $user       = $session->getUser();

            if (Arrays::is($user)) {
                $id = isAke($user, 'id', null);

                if (!is_null($id)) {
                    $user = $this->userDb->find($id);
                }
            }

            if (is_null($user) || !$user instanceof Container) {
                if (false === $isSha1) {
                    $user = $this->userDb
                    ->username($username)
                    ->password(sha1($password))
                    ->first(true);
                } else {
                    $user = $this->userDb
                    ->username($username)
                    ->password($password)
                    ->first(true);
                }

                if (!is_null($user)) {
                    $this->user = $user;
                    $session->setUser($user->assoc());

                    return true;
                }
            } else {
                $this->user = $user;

                return true;
            }

            return false;
        }

        public function logout()
        {
            $session    = session(Config::get('bundle.auth.session', 'auth'));
            $user       = $session->getUser();

            if (!is_null($user) && isset($this->user)) {
                unset($this->user);

                $session->erase('user');
            }

            return $this;
        }

        public function is($role)
        {
            if ($role instanceof Container) {
                $role = $role->getName();
            }

            if ($this->userRoles instanceof Collection) {
                $userRoles = $this->userRoles->rows();

                foreach ($userRoles as $userRole) {
                    if ($userRole->role()->getName() == Inflector::lower($role)) {
                        return true;
                    }
                }
            }

            return false;
        }

        public function can($permission)
        {
            if ($permission instanceof Container) {
                $permission = $permission->getName();
            }

            if (count($this->userPermissions)) {
                foreach ($this->userPermissions as $userPermissions) {
                    if ($userPermissions instanceof Collection) {
                        foreach ($userPermissions->rows() as $userPermission) {

                            $tmpPermissions = $userPermission->permission();

                            if (is_null($tmpPermissions)) return false;

                            if ($userPermission->permission()->getName() == Inflector::lower($permission)) {
                                return true;
                            }
                        }
                    }
                }
            }

            return false;
        }

        public function cannot($permission)
        {
            return !$this->can($permission);
        }

        public function getRoles()
        {
            return $this->userRoles;
        }

        public function getRole()
        {
            return 0 < $this->userRoles->count() ? $this->userRoles->first()->role() : null;
        }

        private function config()
        {
            $files = glob(__DIR__ . DS . 'config' . DS . '*.php');

            if (count($files)) {
                foreach ($files as $file) {
                    require_once $file;
                }
            }
        }
    }
