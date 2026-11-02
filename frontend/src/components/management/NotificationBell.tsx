import { Bell } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link, useLocation } from 'react-router-dom'

import { notificationService } from '@/services/notificationService'
import type { BellNotification } from '@/types/notification'

const REFRESH_MS = 3 * 60 * 1000

export function NotificationBell() {
  const location = useLocation()
  const [items, setItems] = useState<BellNotification[]>([])
  const [open, setOpen] = useState(false)

  useEffect(() => {
    let cancelled = false
    const load = () =>
      notificationService
        .list()
        .then((data) => {
          if (!cancelled) setItems(data)
        })
        .catch(() => undefined)
    void load()
    const timer = window.setInterval(load, REFRESH_MS)
    return () => {
      cancelled = true
      window.clearInterval(timer)
    }
  }, [location.pathname])

  const total = items.length
  const label = total === 0 ? 'Notifications, nothing needs action' : `Notifications, ${total} need action`

  return (
    <div className="relative">
      <button
        type="button"
        className="relative rounded-md p-2 text-gray-600 hover:bg-gray-100"
        aria-label={label}
        aria-expanded={open}
        aria-haspopup="true"
        onClick={() => setOpen((v) => !v)}
      >
        <Bell className="h-5 w-5" />
        {total > 0 && (
          <span className="absolute -top-0.5 -right-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-error-600 px-1 text-[10px] font-semibold text-white">
            {total > 9 ? '9+' : total}
          </span>
        )}
      </button>
      {open && (
        <>
          <div className="fixed inset-0 z-10" onClick={() => setOpen(false)} />
          <div className="fixed inset-x-4 top-16 z-20 overflow-hidden rounded-lg border border-gray-100 bg-white shadow-lg sm:absolute sm:inset-x-auto sm:top-full sm:right-0 sm:mt-1 sm:w-96">
            <p className="border-b border-gray-100 px-4 py-3 text-sm font-semibold text-gray-900">Needs your action</p>
            {total === 0 ? (
              <p className="px-4 py-6 text-center text-sm text-gray-500">You&rsquo;re all caught up.</p>
            ) : (
              <ul className="max-h-96 divide-y divide-gray-100 overflow-y-auto">
                {items.map((item) => (
                  <li key={item.key}>
                    <Link to={item.to} className="flex items-start gap-3 px-4 py-3 text-sm text-gray-700 hover:bg-gray-50" onClick={() => setOpen(false)}>
                      {/^[a-z]/.test(item.label) ? (
                        <span>
                          <span className="font-semibold text-gray-900">{item.count}</span> {item.label}
                        </span>
                      ) : (
                        <span>{item.label}</span>
                      )}
                    </Link>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </>
      )}
    </div>
  )
}
