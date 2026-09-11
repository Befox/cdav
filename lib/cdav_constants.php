<?php
// Compatibility aliases used in the existing serializers. Configuration is loaded
// once, after the target entity has been resolved by the stateless entry point.
foreach (array('CDAV_CONTACT_TAG' => '', 'CDAV_URI_KEY' => '', 'CDAV_TASK_USER_ROLE' => '0',
	'CDAV_SYNC_PAST' => '31', 'CDAV_SYNC_FUTURE' => '365', 'CDAV_TASK_SYNC' => '0',
	'CDAV_INTERV_SYNC' => '0', 'CDAV_INTERV_USER_ROLE' => '0', 'CDAV_THIRD_SYNC' => '0', 'CDAV_MEMBER_SYNC' => '0') as $name => $default) {
	if (!defined($name)) define($name, getDolGlobalString($name, $default));
}
