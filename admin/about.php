<?php
/* Copyright (C) 2026 Pierre Ardoin <developpeur@lesmetiersdubatiment.fr>
 * SPDX-License-Identifier: GPL-3.0-or-later
 */
$cdavAdminTab = 'about';
require __DIR__.'/_common.php';
print '<div class="fichecenter"><div class="fichehalfleft"><div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent"><tr class="liste_titre"><th colspan="2">'.$langs->trans('About').'</th></tr>';
$metadata = array(
	'Name' => $moduleDescriptor->name,
	'Version' => $moduleDescriptor->version,
	'CDavEditor' => $moduleDescriptor->editor_name,
	'CDavMaintainer' => $moduleDescriptor->maintainer_name,
	'Description' => $langs->trans($moduleDescriptor->description),
	'CDavMinimum' => 'Dolibarr '.implode('.', $moduleDescriptor->need_dolibarr_version).' / PHP '.implode('.', $moduleDescriptor->phpmin),
	'License' => $moduleDescriptor->license,
	'CDavRequiredModules' => $moduleDescriptor->depends ? implode(', ', $moduleDescriptor->depends) : $langs->trans('None'),
	'CDavRequiredBy' => $moduleDescriptor->requiredby ? implode(', ', $moduleDescriptor->requiredby) : $langs->trans('None'),
);
foreach ($metadata as $label => $value) {
	print '<tr class="oddeven"><td class="titlefield">'.$langs->trans($label).'</td><td>'.dol_escape_htmltag($value).'</td></tr>';
}
print '</table></div></div><div class="fichehalfright"><div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent"><tr class="liste_titre"><th>'.$langs->trans('CDavResources').'</th></tr>';
foreach (array('CDavSource' => $moduleDescriptor->maintainer_url, 'CDavEditor' => $moduleDescriptor->editor_url, 'Documentation' => dol_buildpath('/cdav/README.md', 1)) as $label => $url) {
	print '<tr class="oddeven"><td><a target="_blank" rel="noopener noreferrer" href="'.dol_escape_htmltag($url).'">'.$langs->trans($label).'</a></td></tr>';
}
print '</table></div></div><div class="clearboth"></div></div><br>';
$aboutMessage = $langs->trans('CDavAboutFeatures');
$aboutMessage .= '<br><br><a href="'.dol_buildpath('/cdav/admin/compatibility.php', 1).'">'.$langs->trans('CDavAboutDependencies').'</a>';
print get_htmloutput_mesg($aboutMessage, array(), 'info', 1);
print dol_get_fiche_end();
llxFooter();
$db->close();
