import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { AlertTriangle, Building2, CalendarDays, Lock } from 'lucide-react'
import { toast } from 'sonner'
import { messageErreurApi } from '../../lib/erreurs'
import { choisirEtablissement, definirAnnee, fetchContexte } from './contexteApi'

/**
 * Contexte de travail affiché en permanence dans l'en-tête : établissement de
 * rattachement et année scolaire consultée. L'année est modifiable pour consulter
 * les années précédentes ; une année clôturée reste en consultation seule.
 */
export default function ContexteBarre() {
  const qc = useQueryClient()
  const { data } = useQuery({ queryKey: ['contexte'], queryFn: fetchContexte, retry: false })

  const changerAnnee = useMutation({
    mutationFn: definirAnnee,
    onSuccess: () => {
      // L'année borne côté serveur presque toutes les données de l'application
      // (élèves, classes, niveaux, enseignants, absences, notes, rapports, tableau de
      // bord…). On invalide donc tout le cache plutôt que d'énumérer les écrans, au
      // risque d'en oublier un à chaque nouvelle page.
      qc.invalidateQueries()
    },
    // Refusé : la liste revient d'elle-même sur l'année en vigueur (elle suit le serveur),
    // mais il faut le dire — sinon on croirait consulter l'année choisie.
    onError: (e) => toast.error(`L’année n’a pas été changée. ${messageErreurApi(e)}`),
  })

  // L'établissement borne lui aussi toutes les données : même invalidation que l'année.
  const changerEtablissement = useMutation({
    mutationFn: choisirEtablissement,
    onSuccess: () => qc.invalidateQueries(),
    onError: (e) => toast.error(`L’établissement n’a pas été changé. ${messageErreurApi(e)}`),
  })

  if (!data) return null

  const disponibles = data.disponibles ?? []
  const sansEtablissement = !data.etablissement_code
  const anneeCourante = data.annees?.find((a) => a.libelle === data.annee)
  const clotureeSelectionnee = Boolean(anneeCourante?.cloturee)

  return (
    <div className="flex flex-wrap items-center gap-2">
      {/* Établissement — choisi dans une liste, comme l'année juste à côté, sans passer
          par une page dédiée. Sans établissement de travail, plusieurs actes échouent (la
          création de l'accès d'un enseignant ou d'un parent, notamment) : la pastille se
          signale alors comme un avertissement. */}
      {disponibles.length > 0 ? (
        <label
          className="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-bold"
          style={sansEtablissement
            ? { borderColor: 'var(--warning)', background: 'var(--warning-bg)', color: 'var(--warning-text)' }
            : { borderColor: 'var(--border)' }}
          title={sansEtablissement
            ? 'Aucun établissement de travail : choisissez-en un. Certaines actions, comme la création des accès, en dépendent.'
            : data.etablissement_par_defaut
              ? 'Établissement de rattachement de votre compte — choisissez-en un autre pour en changer'
              : 'Établissement de travail'}
        >
          {sansEtablissement ? <AlertTriangle size={13} /> : <Building2 size={13} />}
          <select
            value={data.etablissement_code ?? ''}
            disabled={changerEtablissement.isPending}
            onChange={(e) => e.target.value && changerEtablissement.mutate(e.target.value)}
            className="max-w-56 cursor-pointer truncate border-0 bg-transparent pr-1 text-xs font-bold outline-none"
            style={{ color: 'inherit' }}
            aria-label="Établissement de travail"
          >
            {/* L'établissement courant peut ne plus figurer parmi les disponibles (affectation
                retirée entre-temps) : on le garde affiché plutôt que de montrer un autre nom. */}
            {sansEtablissement && <option value="">Choisir un établissement</option>}
            {!sansEtablissement && !disponibles.some((e) => e.code === data.etablissement_code) && (
              <option value={data.etablissement_code}>{data.etablissement_nom}</option>
            )}
            {disponibles.map((e) => (
              <option key={e.code} value={e.code}>{e.intitule}</option>
            ))}
          </select>
        </label>
      ) : (
        // Aucun établissement accessible : rien à choisir, la page dit pourquoi et quoi faire.
        <Link
          to="/choisir-etablissement"
          className="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-bold transition hover:opacity-80"
          style={data.etablissement_nom
            ? { borderColor: 'var(--border)' }
            : { borderColor: 'var(--warning)', background: 'var(--warning-bg)', color: 'var(--warning-text)' }}
        >
          {data.etablissement_nom ? <Building2 size={13} /> : <AlertTriangle size={13} />}
          <span>{data.etablissement_nom || 'Aucun établissement'}</span>
        </Link>
      )}

      {/* Année scolaire */}
      {data.annees?.length > 0 && (
        <label
          className="inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium"
          style={{
            borderColor: clotureeSelectionnee ? 'var(--warning)' : 'var(--border)',
            background: clotureeSelectionnee ? 'var(--warning-bg)' : 'transparent',
            color: clotureeSelectionnee ? 'var(--warning-text)' : undefined,
          }}
          title={clotureeSelectionnee
            ? 'Année clôturée : consultation seule'
            : 'Année scolaire de travail'}
        >
          {clotureeSelectionnee ? <Lock size={12} /> : <CalendarDays size={13} />}
          <select
            value={data.annee ?? ''}
            disabled={changerAnnee.isPending}
            onChange={(e) => changerAnnee.mutate(e.target.value)}
            className="cursor-pointer border-0 bg-transparent pr-1 text-xs font-medium outline-none"
            style={{ color: 'inherit' }}
          >
            {data.annees.map((a) => (
              <option key={a.libelle} value={a.libelle}>
                {a.libelle}
                {a.active ? ' • en cours' : ''}
                {a.cloturee ? ' • clôturée' : ''}
              </option>
            ))}
          </select>
        </label>
      )}
    </div>
  )
}
