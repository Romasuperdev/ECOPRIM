import { useEffect, useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useNavigate } from 'react-router-dom'
import {
  BellOff, BellRing, BookOpen, CalendarX, CheckCheck, ClipboardList, Clock, FileCheck, Megaphone, MessageCircle, UserPlus,
} from 'lucide-react'
import BandeauPortail from '../../components/ui/BandeauPortail'
import { fetchNotifications, marquerNotificationLue, toutMarquerLu } from './notificationsApi'
import { activerPush, desactiverPush, etatPush } from '../../lib/pwa'

const ICONES = {
  absence: CalendarX,
  retard: Clock,
  justificatif: FileCheck,
  note: ClipboardList,
  devoir: BookOpen,
  annonce: Megaphone,
  message: MessageCircle,
  preinscription: UserPlus,
}

function quand(date) {
  if (!date) return ''
  const d = new Date(String(date).replace(' ', 'T'))
  if (Number.isNaN(d.getTime())) return ''
  const minutes = Math.round((Date.now() - d.getTime()) / 60_000)
  if (minutes < 1) return 'à l’instant'
  if (minutes < 60) return `il y a ${minutes} min`
  if (minutes < 24 * 60) return `il y a ${Math.round(minutes / 60)} h`
  return d.toLocaleDateString('fr-FR', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })
}

/** Notifications des espaces Enseignant et Parent, et réglage des alertes du téléphone. */
export default function NotificationsPage() {
  const qc = useQueryClient()
  const navigate = useNavigate()
  const { data, isLoading } = useQuery({ queryKey: ['notifications'], queryFn: fetchNotifications })

  const rafraichir = () => {
    qc.invalidateQueries({ queryKey: ['notifications'] })
    qc.invalidateQueries({ queryKey: ['notifications-compteur'] })
  }
  const lire = useMutation({ mutationFn: marquerNotificationLue, onSuccess: rafraichir })
  const toutLire = useMutation({ mutationFn: toutMarquerLu, onSuccess: rafraichir })

  const ouvrir = (n) => {
    if (!n.lue) lire.mutate(n.id)
    if (n.lien) navigate(n.lien)
  }

  const notifications = data?.notifications ?? []
  const nonLues = data?.non_lues ?? 0

  return (
    <div>
      <div className="mb-5">
        <BandeauPortail
          titre="Notifications"
          sousTitre={nonLues ? `${nonLues} non lue${nonLues > 1 ? 's' : ''}` : 'Tout est lu.'}
        />
      </div>

      <CartePush />

      <div className="mb-3 flex items-center justify-between">
        <h2 className="text-base font-bold text-heading">Récentes</h2>
        {nonLues > 0 && (
          <button
            type="button"
            onClick={() => toutLire.mutate()}
            disabled={toutLire.isPending}
            className="inline-flex items-center gap-1.5 text-sm font-bold"
            style={{ color: 'var(--brand-accent)' }}
          >
            <CheckCheck size={16} /> Tout marquer comme lu
          </button>
        )}
      </div>

      {isLoading && <p className="text-muted">Chargement…</p>}
      {!isLoading && notifications.length === 0 && (
        <div className="card p-8 text-center text-muted">Aucune notification pour le moment.</div>
      )}

      <ul className="space-y-2">
        {notifications.map((n) => {
          const Icone = ICONES[n.type] ?? BellRing
          return (
            <li key={n.id}>
              <button
                type="button"
                onClick={() => ouvrir(n)}
                className="card flex w-full items-start gap-3 p-4 text-left transition active:scale-[.99]"
                style={n.lue ? undefined : { borderColor: 'var(--brand-accent)' }}
              >
                <span
                  className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full"
                  style={{ background: 'var(--surface-2)', color: 'var(--brand-accent)' }}
                >
                  <Icone size={19} />
                </span>
                <span className="min-w-0 flex-1">
                  <span className="flex items-start justify-between gap-2">
                    <span className={`text-sm text-heading ${n.lue ? 'font-semibold' : 'font-extrabold'}`}>{n.titre}</span>
                    <span className="shrink-0 text-xs text-muted">{quand(n.date)}</span>
                  </span>
                  {n.corps && <span className="mt-0.5 block text-sm text-muted">{n.corps}</span>}
                </span>
                {!n.lue && <span className="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full" style={{ background: 'var(--brand-accent)' }} aria-label="Non lue" />}
              </button>
            </li>
          )
        })}
      </ul>
    </div>
  )
}

const TEXTES_ETAT = {
  actif: 'Ce téléphone reçoit les notifications de l’école, même application fermée.',
  inactif: 'Activez les alertes pour être prévenu tout de suite, même application fermée.',
  refuse: 'Les notifications sont bloquées pour ce site. Autorisez-les dans les réglages du navigateur, puis revenez ici.',
  installer: 'Sur iPhone, installez d’abord l’application (Partager → Sur l’écran d’accueil), puis ouvrez-la depuis son icône pour activer les alertes.',
  indisponible: 'Ce navigateur ne permet pas les alertes. Les notifications restent visibles ici.',
}

/** Activer ou couper les alertes push de CE téléphone. */
function CartePush() {
  const [etat, setEtat] = useState(null)
  const [erreur, setErreur] = useState(null)
  const [enCours, setEnCours] = useState(false)

  useEffect(() => {
    etatPush().then(setEtat).catch(() => setEtat('indisponible'))
  }, [])

  if (etat === null) return null

  const basculer = async () => {
    setErreur(null)
    setEnCours(true)
    try {
      setEtat(etat === 'actif' ? await desactiverPush() : await activerPush())
    } catch (e) {
      setErreur(e?.response?.data?.message ?? e?.message ?? 'Activation impossible.')
    } finally {
      setEnCours(false)
    }
  }

  const actionnable = etat === 'actif' || etat === 'inactif'

  return (
    <div className="card mb-5 flex items-start gap-3 p-4">
      <span
        className="flex h-10 w-10 shrink-0 items-center justify-center rounded-full"
        style={{ background: 'var(--surface-2)', color: etat === 'actif' ? 'var(--success)' : 'var(--muted-text)' }}
      >
        {etat === 'actif' ? <BellRing size={19} /> : <BellOff size={19} />}
      </span>
      <div className="min-w-0 flex-1">
        <p className="text-sm font-bold text-heading">Alertes sur ce téléphone</p>
        <p className="mt-0.5 text-sm text-muted">{TEXTES_ETAT[etat]}</p>
        {erreur && <p className="mt-1 text-sm" style={{ color: 'var(--danger-text)' }}>{erreur}</p>}
      </div>
      {actionnable && (
        <button
          type="button"
          onClick={basculer}
          disabled={enCours}
          className="shrink-0 rounded-full px-4 py-2 text-sm font-bold disabled:opacity-50"
          style={etat === 'actif'
            ? { background: 'var(--surface-2)', color: 'var(--heading)' }
            : { background: 'var(--brand-accent)', color: 'var(--brand-accent-ink)' }}
        >
          {etat === 'actif' ? 'Couper' : 'Activer'}
        </button>
      )}
    </div>
  )
}
