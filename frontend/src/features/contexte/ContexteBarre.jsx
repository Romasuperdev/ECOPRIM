import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { AlertTriangle, Building2, CalendarDays, Lock } from 'lucide-react'
import { definirAnnee, fetchContexte } from './contexteApi'

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
  })

  if (!data) return null

  const anneeCourante = data.annees?.find((a) => a.libelle === data.annee)
  const clotureeSelectionnee = Boolean(anneeCourante?.cloturee)

  return (
    <div className="flex flex-wrap items-center gap-2">
      {/* Établissement */}
      {/* Sans établissement de travail, plusieurs actes échouent — la création de l'accès
          d'un enseignant ou d'un parent, notamment, faute de savoir à quelle école le
          rattacher. La pastille annonçait « Aucun établissement » en gris discret : un
          constat, que rien ne désignait comme l'endroit où le corriger. Elle devient une
          invitation, et se signale comme le fait un avertissement. */}
      <Link
        to="/choisir-etablissement"
        className="inline-flex items-center gap-2 rounded-full border px-3 py-1 text-xs font-bold transition hover:opacity-80"
        style={data.etablissement_nom
          ? { borderColor: 'var(--border)' }
          : {
              borderColor: 'var(--warning)',
              background: 'var(--warning-bg)',
              color: 'var(--warning-text)',
            }}
        title={data.etablissement_nom
          ? (data.etablissement_par_defaut
            ? 'Établissement de rattachement de votre compte — cliquez pour en changer'
            : "Changer d'établissement")
          : "Aucun établissement de travail : cliquez pour en choisir un. Certaines actions, comme la création des accès, en dépendent."}
      >
        {data.etablissement_nom ? <Building2 size={13} /> : <AlertTriangle size={13} />}
        <span>{data.etablissement_nom || 'Choisir un établissement'}</span>
      </Link>

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
