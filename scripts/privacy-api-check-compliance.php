<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Helper utility to check for compliance of all plugins.
 *
 * @copyright 2018 Cblue sprl
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @package   mod_wooclap
 */

// How to use this script?
// https://docs.moodle.org/dev/Privacy_API/Utilities .

define('CLI_SCRIPT', true);
require(__DIR__.'/../../../config.php');

// Set this if you want to run the script for one component only. Otherwise leave empty.
$checkcomponent = '';

$user = \core_user::get_user(2);

\core\session\manager::init_empty_session();
\core\session\manager::set_user($user);

$rc = new \ReflectionClass(\core_privacy\manager::class);
$rcm = $rc->getMethod('get_component_list');
$rcm->setAccessible(true);

$manager = new \core_privacy\manager();
$components = $rcm->invoke($manager);

$list = (object) [
    'good' => [],
    'bad' => [],
];

foreach ($components as $component) {
    if ($checkcomponent && $component !== $checkcomponent) {
        continue;
    }
    $compliant = $manager->component_is_compliant($component);
    if ($compliant) {
        $list->good[] = $component;
    } else {
        $list->bad[] = $component;
    }
}

echo "The following plugins are not compliant:\n";
echo "=> " . implode("\n=> ", array_values($list->bad)) . "\n";

echo "\n";
echo "Testing the compliant plugins:\n";
foreach ($list->good as $component) {
    $classname = \core_privacy\manager::get_provider_classname_for_component($component);
    echo "== {$component} ($classname) ==\n";
    if (check_implements($component, \core_privacy\local\metadata\null_provider::class)) {
        echo "    Claims not to store any data with reason:\n";
        echo "      '" . get_string($classname::get_reason(), $component) . "'\n";
    } else if (check_implements($component, \core_privacy\local\metadata\provider::class)) {
        $collection = new \core_privacy\local\metadata\collection($component);
        $classname::get_metadata($collection);
        $count = count($collection);
        echo "    Found {$count} items of metadata\n";
        if (empty($count)) {
            echo "!!! No metadata found!!! This an error.\n";
        }

        if (check_implements($component, \core_privacy\local\request\user_preference_provider::class)) {
            $userprefdescribed = false;
            foreach ($collection->get_collection() as $item) {
                if ($item instanceof \core_privacy\local\metadata\types\user_preference) {
                    $userprefdescribed = true;
                    echo "     " . $item->get_name() . " : " . get_string($item->get_summary(), $component) . "\n";
                }
            }
            if (!$userprefdescribed) {
                echo "!!! User preference found, but was not described in metadata\n";
            }
        }

        if (check_implements($component, \core_privacy\local\request\core_user_data_provider::class)) {
            // No need to check the return type - it's enforced by the interface.
            $contextlist = $classname::get_contexts_for_userid($user->id);
            $approvedcontextlist = new \core_privacy\local\request\approved_contextlist(
                $user,
                $contextlist->get_component(),
                $contextlist->get_contextids()
            );

            if (count($approvedcontextlist)) {
                $classname::export_user_data($approvedcontextlist);
                echo "    Successfully ran a test export\n";
            } else {
                echo "    Nothing to export.\n";
            }
        }
        if (check_implements($component, \core_privacy\local\request\shared_data_provider::class)) {
            echo "    This is a shared data provider\n";
        }
    }
}

echo "\n\n== Done ==\n";

/**
 * Completion and grade update endpoint
 *
 * @param string $component
 * @param string $interface
 * @return mixed
 * @throws ReflectionException
 */
function check_implements($component, $interface) {
    $manager = new \core_privacy\manager();
    $rc = new \ReflectionClass(\core_privacy\manager::class);
    $rcm = $rc->getMethod('component_implements');
    $rcm->setAccessible(true);

    return $rcm->invoke($manager, $component, $interface);
}
