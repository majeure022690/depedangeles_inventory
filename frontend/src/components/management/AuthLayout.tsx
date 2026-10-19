import { ArrowLeft } from 'lucide-react'
import { type ReactNode } from 'react'
import { Link } from 'react-router-dom'

import { BrandMarks } from '@/components/BrandMarks'
import { BRANDING } from '@/lib/branding'

interface AuthLayoutProps {
  /** Omitted on the sign-in page itself — there's no public site to go back to. */
  backTo?: string
  backLabel?: string
  children: ReactNode
}

export function AuthLayout({ backTo, backLabel, children }: AuthLayoutProps) {
  return (
    <div className="flex min-h-screen bg-gray-50">
      <div className="relative hidden w-1/2 overflow-hidden lg:flex">
        <div className="absolute inset-0 bg-gradient-to-br from-primary-600 to-primary-800" />

        <div className="relative flex w-full items-center justify-center p-10 text-white">
          <div className="relative max-w-md text-center">
            <BrandMarks className="mb-6 justify-center gap-3" imageClassName="h-24 w-24" />
            <h1 className="text-3xl font-bold">{BRANDING.appName}</h1>
            <p className="mt-1 text-lg font-medium text-primary-100">{BRANDING.fullName}</p>
            <p className="mt-3 text-primary-100">{BRANDING.tagline}</p>
          </div>
        </div>
      </div>

      <div className="flex w-full flex-col items-center justify-center px-4 py-8 lg:w-1/2 lg:py-12">
        <div className="w-full max-w-sm">
          {backTo && backLabel && (
            <Link to={backTo} className="mb-4 inline-flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700 lg:mb-6">
              <ArrowLeft className="h-4 w-4" />
              {backLabel}
            </Link>
          )}

          <div className="relative mb-4 overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm lg:mb-6 lg:hidden">
            <div className="absolute inset-0 bg-gradient-to-br from-primary-600 to-primary-800" />
            <div className="absolute inset-0 bg-white/80" />
            <div className="relative flex items-center justify-center gap-2.5 px-3 py-3">
              <BrandMarks className="gap-1" imageClassName="h-8 w-8" />
              <div>
                <div className="text-sm font-bold text-gray-900">{BRANDING.appName}</div>
                <div className="text-[9px] uppercase tracking-[0.1em] text-gray-700">{BRANDING.fullName}</div>
              </div>
            </div>
          </div>

          <div className="mb-6 hidden items-center gap-3 lg:flex">
            <BrandMarks imageClassName="h-12 w-12" />
            <div>
              <div className="text-xl font-bold text-gray-900">{BRANDING.appName}</div>
              <div className="text-xs text-gray-500">{BRANDING.fullName}</div>
            </div>
          </div>

          {children}
        </div>
      </div>
    </div>
  )
}
