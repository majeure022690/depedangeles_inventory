import { Boxes, Copy, Laptop, type LucideIcon, Monitor, Network, Printer, Projector, Server, Smartphone, Tablet, Tv } from 'lucide-react'

export interface EquipmentGroupStyle {
  icon: LucideIcon
  tile: string
  badge: string
  chart: string
}

const OTHER: EquipmentGroupStyle = {
  icon: Boxes,
  tile: 'border-gray-200 bg-gray-50',
  badge: 'bg-gray-200 text-gray-700',
  chart: '#64748b',
}

const STYLES: Record<string, EquipmentGroupStyle> = {
  desktop_computer: { icon: Monitor, tile: 'border-primary-100 bg-primary-50', badge: 'bg-primary-100 text-primary-700', chart: '#2563eb' },
  laptop: { icon: Laptop, tile: 'border-indigo-100 bg-indigo-50', badge: 'bg-indigo-100 text-indigo-700', chart: '#4f46e5' },
  tablet: { icon: Tablet, tile: 'border-violet-100 bg-violet-50', badge: 'bg-violet-100 text-violet-700', chart: '#9333ea' },
  printer: { icon: Printer, tile: 'border-sky-100 bg-sky-50', badge: 'bg-sky-100 text-sky-700', chart: '#0284c7' },
  tv: { icon: Tv, tile: 'border-rose-100 bg-rose-50', badge: 'bg-rose-100 text-rose-700', chart: '#e11d48' },
  projector_smartboard: { icon: Projector, tile: 'border-amber-100 bg-amber-50', badge: 'bg-amber-100 text-amber-700', chart: '#d97706' },
  smartphone: { icon: Smartphone, tile: 'border-pink-100 bg-pink-50', badge: 'bg-pink-100 text-pink-700', chart: '#db2777' },
  server: { icon: Server, tile: 'border-emerald-100 bg-emerald-50', badge: 'bg-emerald-100 text-emerald-700', chart: '#059669' },
  copier: { icon: Copy, tile: 'border-orange-100 bg-orange-50', badge: 'bg-orange-100 text-orange-700', chart: '#ea580c' },
  network_switch: { icon: Network, tile: 'border-cyan-100 bg-cyan-50', badge: 'bg-cyan-100 text-cyan-700', chart: '#0891b2' },
  other: OTHER,
}

export function equipmentGroupStyle(key: string): EquipmentGroupStyle {
  return STYLES[key] ?? OTHER
}
