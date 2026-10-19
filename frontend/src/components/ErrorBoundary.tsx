import { Component, type ErrorInfo, type ReactNode } from 'react'
import { AlertTriangle, RefreshCw } from 'lucide-react'

export function RouteErrorFallback() {
  return (
    <div className="rounded-lg border border-error-200 bg-white p-6 shadow-sm">
      <div className="flex items-start gap-3">
        <AlertTriangle className="h-6 w-6 shrink-0 text-error-600" />
        <div className="flex-1">
          <h2 className="text-lg font-semibold text-gray-900">Something went wrong</h2>
          <p className="mt-2 text-sm text-gray-600">
            This page ran into an unexpected error. Try reloading — the rest of the app is unaffected.
          </p>
          <button
            onClick={() => window.location.reload()}
            className="mt-4 inline-flex items-center gap-2 rounded-md bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 dark:hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2"
          >
            <RefreshCw className="h-4 w-4" />
            Reload Page
          </button>
        </div>
      </div>
    </div>
  )
}

interface Props {
  children: ReactNode
  fallback?: ReactNode
}

interface State {
  hasError: boolean
  error: Error | null
  errorInfo: ErrorInfo | null
}

export class ErrorBoundary extends Component<Props, State> {
  constructor(props: Props) {
    super(props)
    this.state = {
      hasError: false,
      error: null,
      errorInfo: null,
    }
  }

  static getDerivedStateFromError(error: Error): Partial<State> {
    return {
      hasError: true,
      error,
    }
  }

  componentDidCatch(error: Error, errorInfo: ErrorInfo) {
    // Log error to console in development
    if (import.meta.env.DEV) {
      console.error('ErrorBoundary caught an error:', error, errorInfo)
    }

    // In production, you might want to log to an error reporting service
    // Example: logErrorToService(error, errorInfo)

    this.setState({
      error,
      errorInfo,
    })
  }

  handleReset = () => {
    this.setState({
      hasError: false,
      error: null,
      errorInfo: null,
    })
  }

  handleReload = () => {
    window.location.reload()
  }

  render() {
    if (this.state.hasError) {
      // Use custom fallback if provided
      if (this.props.fallback) {
        return this.props.fallback
      }

      // Default error UI
      return (
        <div className="flex min-h-screen items-center justify-center bg-gray-50 px-4">
          <div className="w-full max-w-md">
            <div className="rounded-lg border border-error-200 bg-white p-6 shadow-sm">
              <div className="flex items-start gap-3">
                <div className="flex-shrink-0">
                  <AlertTriangle className="h-6 w-6 text-error-600" />
                </div>
                <div className="flex-1">
                  <h2 className="text-lg font-semibold text-gray-900">Something went wrong</h2>
                  <p className="mt-2 text-sm text-gray-600">
                    An unexpected error occurred. Please try refreshing the page or contact support if the problem
                    persists.
                  </p>

                  {import.meta.env.DEV && this.state.error && (
                    <details className="mt-4">
                      <summary className="cursor-pointer text-sm font-medium text-gray-700 hover:text-gray-900">
                        Error details (dev only)
                      </summary>
                      <div className="mt-2 rounded bg-gray-50 p-3">
                        <p className="text-xs font-mono text-error-600">{this.state.error.toString()}</p>
                        {this.state.errorInfo && (
                          <pre className="mt-2 max-h-40 overflow-auto text-xs text-gray-600">
                            {this.state.errorInfo.componentStack}
                          </pre>
                        )}
                      </div>
                    </details>
                  )}

                  <div className="mt-6 flex gap-3">
                    <button
                      onClick={this.handleReload}
                      className="inline-flex items-center gap-2 rounded-md bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 dark:hover:bg-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2"
                    >
                      <RefreshCw className="h-4 w-4" />
                      Reload Page
                    </button>
                    <button
                      onClick={this.handleReset}
                      className="inline-flex items-center rounded-md border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2"
                    >
                      Try Again
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      )
    }

    return this.props.children
  }
}
