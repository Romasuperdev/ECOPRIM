import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { GraduationCap, ShieldCheck, Mail, Lock, Eye, EyeOff } from 'lucide-react'
import Button from '../../components/ui/Button'
import Input from '../../components/ui/Input'
import { useAuthStore } from '../../store/authStore'
import { ROLES } from '../../lib/constants'
import { login, logout } from './authApi'

// Deux paires de champs indépendantes (une par face du panneau). Contrôlées via useState,
// pas React Hook Form : chaque face est montée deux fois en simultané (desktop + mobile,
// juste basculées en CSS pour l'animation coulissante), et RHF ne peut suivre qu'une seule
// ref par nom de champ — deux <input> montés pour le même register() se marchent dessus.
// Un state contrôlé partagé n'a pas ce problème : React re-rend simplement les deux copies
// avec la même valeur.
function useCredentials() {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [errors, setErrors] = useState({})

  const validate = () => {
    const next = {}
    if (!email.trim()) next.email = "L'identifiant est requis"
    if (!password) next.password = 'Le mot de passe est requis'
    setErrors(next)
    return Object.keys(next).length === 0
  }

  return { email, setEmail, password, setPassword, errors, validate }
}

export default function LoginPage() {
  const [tab, setTab] = useState('etablissement') // 'etablissement' | 'admin'
  const [serverError, setServerError] = useState(null)
  const [isSubmitting, setIsSubmitting] = useState(false)
  const [showPassword, setShowPassword] = useState(false)
  const navigate = useNavigate()
  const setUser = useAuthStore((state) => state.setUser)

  const credsEtablissement = useCredentials()
  const credsAdmin = useCredentials()

  const switchTo = (key) => {
    setTab(key)
    setServerError(null)
  }

  const submit = async (kind, e) => {
    e.preventDefault()
    setServerError(null)

    const creds = kind === 'admin' ? credsAdmin : credsEtablissement
    if (!creds.validate()) return

    setIsSubmitting(true)
    try {
      const user = await login({ email: creds.email, password: creds.password })

      if (kind === 'admin' && !user.roles?.includes(ROLES.SUPER_ADMIN)) {
        await logout()
        setServerError("Ce compte n'a pas accès à la console administrateur.")
        return
      }

      setUser(user)
      navigate(kind === 'admin' ? '/admin' : '/', { replace: true })
    } catch (error) {
      if (error.response?.status === 422 || error.response?.status === 401) {
        setServerError('Identifiant ou mot de passe incorrect.')
      } else {
        setServerError('Une erreur est survenue. Veuillez réessayer.')
      }
    } finally {
      setIsSubmitting(false)
    }
  }

  const renderForm = (kind, context) => {
    const isConsole = kind === 'admin'
    const creds = isConsole ? credsAdmin : credsEtablissement
    const fieldId = (name) => `${name}-${kind}-${context}`

    return (
      <form
        onSubmit={(e) => submit(kind, e)}
        className="mx-auto flex w-full max-w-[320px] flex-col gap-4"
        noValidate
      >
        <div className="mb-1 flex items-center gap-2" style={{ color: isConsole ? 'var(--sidebar)' : 'var(--accent)' }}>
          {isConsole ? <ShieldCheck size={26} /> : <GraduationCap size={26} />}
          <h2 className="text-2xl font-extrabold text-heading">{isConsole ? 'Console Admin' : 'Établissement'}</h2>
        </div>
        <p className="-mt-2 text-sm text-muted">
          {isConsole ? 'Espace Super Administrateur.' : 'Accédez à votre établissement.'}
        </p>

        <Input
          id={fieldId('email')}
          type="text"
          label="Identifiant ou email"
          icon={Mail}
          autoComplete="username"
          error={creds.errors.email}
          value={creds.email}
          onChange={(e) => creds.setEmail(e.target.value)}
        />
        <Input
          id={fieldId('password')}
          type={showPassword ? 'text' : 'password'}
          label="Mot de passe"
          icon={Lock}
          autoComplete="current-password"
          error={creds.errors.password}
          value={creds.password}
          onChange={(e) => creds.setPassword(e.target.value)}
          rightElement={
            <button
              type="button"
              onClick={() => setShowPassword((v) => !v)}
              className="text-muted hover:opacity-70"
              tabIndex={-1}
            >
              {showPassword ? <EyeOff size={16} /> : <Eye size={16} />}
            </button>
          }
        />

        {serverError && tab === kind && (
          <p className="rounded-lg p-2 text-sm text-red-600" style={{ background: 'rgba(220,38,38,.1)' }}>
            {serverError}
          </p>
        )}

        <Button type="submit" variant={isConsole ? 'primary' : 'gold'} disabled={isSubmitting} className="w-full justify-center py-3 text-base">
          {isSubmitting ? 'Connexion…' : isConsole ? 'Accéder à la console' : 'Se connecter'}
        </Button>
      </form>
    )
  }

  const consoleActive = tab === 'admin'

  return (
    <div className="flex min-h-screen items-center justify-center p-4">
      {/* ---------- Grand écran : panneau coulissant ---------- */}
      <div className="card relative hidden overflow-hidden rounded-2xl md:block" style={{ width: 880, height: 560 }}>
        <div className="absolute left-0 top-0 flex h-full items-center justify-center p-10" style={{ width: '50%' }}>
          {renderForm('etablissement', 'desktop')}
        </div>
        <div className="absolute top-0 flex h-full items-center justify-center p-10" style={{ width: '50%', left: '50%' }}>
          {renderForm('admin', 'desktop')}
        </div>
        <div
          className="absolute top-0 z-20 flex h-full items-center justify-center p-10 text-white"
          style={{
            width: '50%',
            left: '50%',
            transform: consoleActive ? 'translateX(-100%)' : 'translateX(0)',
            transition: 'transform .6s cubic-bezier(.6,.05,.2,1)',
            background: consoleActive
              ? 'linear-gradient(135deg, var(--accent), var(--teal))'
              : 'linear-gradient(135deg, var(--sidebar), var(--sidebar-2))',
          }}
        >
          <div className="flex max-w-[300px] flex-col items-center text-center">
            <span className="text-5xl">🎓</span>
            <div className="mt-2 text-xl font-extrabold tracking-tight">ECOPRIM</div>
            <h2 className="mt-4 text-3xl font-extrabold">Bienvenue</h2>
            {consoleActive ? (
              <>
                <p className="mt-3 text-base text-white/80">Vous gérez un établissement ? Connectez-vous à l'application.</p>
                <button
                  onClick={() => switchTo('etablissement')}
                  className="mt-5 rounded-xl border-2 border-white/80 px-6 py-2.5 text-base font-semibold transition hover:bg-white/10"
                >
                  Établissement
                </button>
              </>
            ) : (
              <>
                <p className="mt-3 text-base text-white/80">Vous êtes administrateur plateforme ? Accédez à la console.</p>
                <button
                  onClick={() => switchTo('admin')}
                  className="mt-5 rounded-xl border-2 border-white/80 px-6 py-2.5 text-base font-semibold transition hover:bg-white/10"
                >
                  Console Admin
                </button>
              </>
            )}
          </div>
        </div>
      </div>

      {/* ---------- Mobile : onglets + formulaire ---------- */}
      <div className="w-full max-w-md md:hidden">
        <div className="mb-5 flex flex-col items-center">
          <span className="text-5xl">🎓</span>
          <div className="mt-1 text-xl font-extrabold text-heading">ECOPRIM</div>
        </div>
        <div className="card rounded-2xl p-6">
          <div className="mb-5 grid grid-cols-2 gap-2 rounded-xl p-1" style={{ background: 'var(--surface-2)' }}>
            <button
              type="button"
              onClick={() => switchTo('etablissement')}
              className="flex items-center justify-center gap-2 rounded-lg py-2 text-sm font-semibold"
              style={!consoleActive ? { background: 'var(--accent)', color: 'var(--accent-ink)' } : { color: 'var(--muted)' }}
            >
              <GraduationCap size={16} /> Établissement
            </button>
            <button
              type="button"
              onClick={() => switchTo('admin')}
              className="flex items-center justify-center gap-2 rounded-lg py-2 text-sm font-semibold"
              style={consoleActive ? { background: 'var(--sidebar)', color: '#fff' } : { color: 'var(--muted)' }}
            >
              <ShieldCheck size={16} /> Console
            </button>
          </div>
          <div className={tab === 'etablissement' ? '' : 'hidden'}>{renderForm('etablissement', 'mobile')}</div>
          <div className={tab === 'admin' ? '' : 'hidden'}>{renderForm('admin', 'mobile')}</div>
        </div>
      </div>
    </div>
  )
}
