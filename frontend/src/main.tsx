import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { BrowserRouter } from 'react-router-dom'
import { GoogleReCaptchaProvider } from 'react-google-recaptcha-v3'

import { App } from '@/App'
import { AuthProvider } from '@/context/AuthContext'
import { recaptchaSiteKey } from '@/lib/recaptcha'

import './index.css'

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <GoogleReCaptchaProvider reCaptchaKey={recaptchaSiteKey}>
      <BrowserRouter>
        <AuthProvider>
          <App />
        </AuthProvider>
      </BrowserRouter>
    </GoogleReCaptchaProvider>
  </StrictMode>,
)
