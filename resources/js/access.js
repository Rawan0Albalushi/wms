export const navigation = [
    ['nav.group.today', [
        ['/', 'nav.floor', 'home', 'floor.view'],
    ]],
    ['nav.group.setup', [
        ['/items', 'nav.items', 'box', 'items.view'],
        ['/locations', 'nav.locations', 'pin', 'locations.view'],
        ['/suppliers', 'nav.suppliers', 'supplier', 'suppliers.view'],
    ]],
    ['nav.group.inbound', [
        ['/procurement', 'nav.procurement', 'cart', 'procurement.view'],
        ['/receiving', 'nav.receiving', 'truck', 'receiving.post'],
    ]],
    ['nav.group.stock', [
        ['/inventory', 'nav.inventory', 'layers', 'inventory.view'],
    ]],
    ['nav.group.outbound', [
        ['/jobs', 'nav.jobs', 'wrench', ['jobs.view', 'jobs.manage']],
        ['/issuing', 'nav.issuing', 'out', 'issuing.post'],
        ['/transfers', 'nav.transfers', 'move', 'transfers.post'],
    ]],
    ['nav.group.follow', [
        ['/counts', 'nav.counts', 'clipboard', 'counts.view'],
        ['/alerts', 'nav.alerts', 'bell', 'alerts.view'],
        ['/reports', 'nav.reports', 'chart', 'reports.view'],
    ]],
    ['nav.group.admin', [
        ['/access', 'nav.access', 'key', 'access.manage'],
    ]],
];

export const permissionGroups = [
    ['nav.group.today', ['floor.view']],
    ['nav.group.setup', ['items.view', 'items.manage', 'locations.view', 'locations.manage', 'suppliers.view', 'suppliers.manage']],
    ['nav.group.inbound', ['procurement.view', 'procurement.manage', 'receiving.post']],
    ['nav.group.stock', ['inventory.view', 'transfers.post']],
    ['nav.group.outbound', ['jobs.view', 'jobs.manage', 'issuing.post']],
    ['nav.group.follow', ['counts.view', 'counts.manage', 'counts.approve', 'alerts.view', 'reports.view']],
    ['nav.group.admin', ['access.manage']],
];

function matches(pathname, to) {
    return pathname === to || pathname.startsWith(`${to}/`);
}

export function permissionFor(pathname) {
    const match = navigation
        .flatMap(([, items]) => items)
        .filter(([to]) => to !== '/' && matches(pathname, to))
        .sort((a, b) => b[0].length - a[0].length)[0];

    return match ? match[3] : 'floor.view';
}
