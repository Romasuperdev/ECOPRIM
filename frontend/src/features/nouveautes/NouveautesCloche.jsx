import { useEffect, useState } from 'react'
import { Link } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Bell, X } from 'lucide-react'
import Button from '../../components/ui/Button'
import useEchap from '../../hooks/useEchap'
import { useAuthStore } from '../../store/authStore'
import VersionCarte from './VersionCarte'
import { CLES_NOUVEAUTES, fetchNouveautesNonLues, marquerNouveauteLue } from './nouveautesApi'

// Une seule annonce par session navigateur et par compte : on la propose, on n'insiste
// pas. Fermer sans valider ne marque rien — la cloche continue de compter la version.
const cleAnnonce = (userId) => `nexora-nouveautes-annoncees-${userId ?? 'anonyme'}`

function dejaAnnoncee(userId) {
  try {
    return sessionStorage.getItem(cleAnnonce(userId)) === '1'
  } catch {
    return false
  }
}

function noterAnnoncee(userId) {
  try {
    sessionStorage.setItem(cleAnnonce(userId), '1')
  } catch {
    // Stockage indisponible (navigation privée stricte) : la modale reviendra au
    // prochain chargement, rien de plus grave.
  }
}

function ModaleAnnonce({ version, onFermer, onValider, enCours }) {
  useEchap(onFermer)

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" onClick={onFermer}>
      <div
        role="dialog" aria-modal="true" aria-labelledby="titre-annonce-nouveautes"
        className="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-card p-6"
        onClick={(e) => e.stopPropagation()}
      >
        <div className="mb-4 flex items-start justify-between gap-3">
          <h2 id="titre-annonce-nouveautes" className="flex items-center gap-2 text-lg font-bold text-heading">
            <Bell size={20} strokeWidth={2.5} style={{ color: 'var(--brand-accent)' }} />
            Nouveautés de la version {version.numero}
          </h2>
          <button type="button" onClick={onFermer} title="Fermer" className="rounded-lg p-1.5 text-muted hover:bg-black/5">
            <X size={18} />
          </button>
        </div>

        <VersionCarte version={version} />

        <div className="mt-5 flex flex-wrap items-center justify-between gap-2">
          <Link to="/nouveautes" onClick={onFermer} className="text-sm font-semibold" style={{ color: 'var(--brand-accent)' }}>
            Voir toutes les nouveautés
          </Link>
          <div className="flex gap-2">
            <Button type="button" variant="outline" onClick={onFermer}>Plus tard</Button>
            <Button type="button" onClick={onValider} disabled={enCours}>
              {enCours ? 'Enregistrement…' : 'J’ai pris connaissance'}
            </Button>
          </div>
        </div>
      </div>
    </div>
  )
}

/**
 * Cloche de l'en-tête : nombre de versions non lues par ce compte, lien vers la page
 * Nouveautés — et, une fois par session, l'annonce de la plus récente non lue.
 */
export default function NouveautesCloche() {
  const qc = useQueryClient()
  const userId = useAuthStore((s) => s.user?.id)
  // Vrai dès que l'annonce a été proposée dans cette session (ou fermée, ou validée).
  const [ecartee, setEcartee] = useState(() => dejaAnnoncee(userId))

  const { data: nonLues = [] } = useQuery({
    queryKey: CLES_NOUVEAUTES.nonLues,
    queryFn: fetchNouveautesNonLues,
    // Un échec ici ne doit rien casser : l'en-tête reste utilisable, la cloche reste muette.
    retry: false,
    staleTime: 5 * 60 * 1000,
  })

  // La plus récente des non lues, tant que l'annonce n'a pas été écartée.
  const annonce = ecartee ? null : (nonLues[0] ?? null)

  // Dès qu'elle s'affiche, la session s'en souvient : un rechargement ne la ressort pas.
  useEffect(() => {
    if (annonce) noterAnnoncee(userId)
  }, [annonce, userId])

  const marquer = useMutation({
    mutationFn: (id) => marquerNouveauteLue(id),
    onSuccess: () => {
      // Préfixe ['nouveautes'] : la cloche ET la page de lecture se rafraîchissent.
      qc.invalidateQueries({ queryKey: CLES_NOUVEAUTES.liste })
      setEcartee(true)
    },
  })

  const nombre = nonLues.length
  const libelle = nombre > 0
    ? `Nouveautés — ${nombre} version${nombre > 1 ? 's' : ''} non lue${nombre > 1 ? 's' : ''}`
    : 'Nouveautés'

  return (
    <>
      <Link
        to="/nouveautes"
        title={libelle}
        aria-label={libelle}
        className="relative rounded-lg p-2 text-heading hover:bg-black/5"
      >
        <Bell size={19} />
        {nombre > 0 && (
          <span
            className="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full px-1 text-[10px] font-bold"
            style={{ background: 'var(--danger)', color: '#ffffff' }}
          >
            {nombre > 9 ? '9+' : nombre}
          </span>
        )}
      </Link>

      {annonce && (
        <ModaleAnnonce
          version={annonce}
          onFermer={() => setEcartee(true)}
          onValider={() => marquer.mutate(annonce.id)}
          enCours={marquer.isPending}
        />
      )}
    </>
  )
}
