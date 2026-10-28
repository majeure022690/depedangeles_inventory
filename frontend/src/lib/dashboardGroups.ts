export const DASHBOARD_GROUP_LABELS: Record<string, string> = {
  desktop_computer: 'Desktop Computer',
  laptop: 'Laptop',
  tablet: 'Tablet',
  printer: 'Printer',
  tv: 'TV',
  projector_smartboard: 'Projector / Smartboard',
  smartphone: 'Smartphone',
  server: 'Server',
  copier: 'Copier / Xerox Machine',
  network_switch: 'Network Switch',
  other: 'Other equipment',
}

export function equipmentListUrlForGroup(key: string): string {
  return `/equipment?dashboard_group=${encodeURIComponent(key)}`
}

export function equipmentListUrlForItemTypes(ids: number[]): string {
  return `/equipment?item_type=${ids.join(',')}`
}
