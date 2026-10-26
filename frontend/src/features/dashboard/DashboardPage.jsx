import { lazy, Suspense, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import {
  CalendarClock,
  GraduationCap,
  School,
  TrendingUp,
  Users,
} from 'lucide-react'
import { useAuthStore } from '../../store/authStore'
import Compteur from '../../components/ui/Compteur'
import MessageErreur from '../../components/ui/MessageErreur'
import { fetchDashboardStats } from './dashboardApi'
import { CarteGraphique } from './CarteGraphique'

// Les graphiques (recharts, ~400 ko) arrivent à part : le bandeau et les tuiles
// s'affichent d'abord, chaque carte montre une place réservée le temps qu'ils chargent.
// Les trois imports pointent le même module : il n'est téléchargé qu'une fois.
const chargerGraphiques = () => import('./graphiques')
const Anneau = lazy(() => chargerGraphiques().then((m) => ({ default: m.Anneau })))
const Jauge = lazy(() => chargerGraphiques().then((m) => ({ default: m.Jauge })))
const HistogrammeMensuel = lazy(() => chargerGraphiques().then((m) => ({ default: m.HistogrammeMensuel })))

/** Place réservée d'un graphique en cours de chargement (même hauteur : rien ne saute). */
function GraphiqueEnAttente({ hauteur }) {
  return (
    <div
      className="h-full animate-pulse rounded-xl"
      style={{ minHeight: hauteur, background: 'var(--surface-2)' }}
      role="status" aria-label="Chargement du graphique"
    />
  )
}

const NATURES = {
  evaluation: { libelle: 'Évaluation', classes: 'bg-primary-100 text-primary-800' },
  devoir: { libelle: 'Devoir', classes: 'bg-green-100 text-green-800' },
  evenement: { libelle: 'Événement', classes: 'bg-amber-100 text-amber-800' },
}

const VUES_MENSUELLES = {
  mouvements: {
    libelle: 'Mouvements d’élèves',
    source: 'inscriptions_par_mois',
    series: [
      { cle: 'inscriptions', libelle: 'Inscriptions', couleur: 'var(--chart-1)' },
      { cle: 'reinscriptions', libelle: 'Réinscriptions', couleur: 'var(--chart-2)' },
      { cle: 'transferts', libelle: 'Transferts', couleur: 'var(--chart-3)' },
    ],
    vide: 'Aucune date d’inscription renseignée sur les élèves de cette année.',
  },
  absences: {
    libelle: 'Absences',
    source: 'absences_par_mois',
    series: [
      { cle: 'justifiees', libelle: 'Justifiées', couleur: 'var(--chart-2)' },
      { cle: 'non_justifiees', libelle: 'Non justifiées', couleur: 'var(--chart-3)' },
    ],
    vide: 'Aucune absence saisie pour cette année.',
  },
}

function Tuile({ icon: Icon, valeur, libelle, precision }) {
  return (
    <div className="card survol-leger flex items-center gap-3 rounded-2xl p-4">
      <div className="shrink-0 rounded-xl bg-primary-100 p-2.5 text-primary-800">
        <Icon size={20} />
      </div>
      <div className="min-w-0">
        <p className="text-2xl font-extrabold leading-tight text-heading">{valeur == null ? '—' : <Compteur valeur={valeur} />}</p>
        <p className="truncate text-sm font-semibold text-heading">{libelle}</p>
        {precision && <p className="truncate text-xs font-medium text-muted">{precision}</p>}
      </div>
    </div>
  )
}

function Agenda({ lignes }) {
  return (
    <div className="card mt-5 overflow-hidden rounded-2xl">
      <div className="flex items-center gap-2 px-5 pt-5">
        <CalendarClock size={18} className="text-heading" />
        <h2 className="text-base font-bold text-heading">Prochaines échéances</h2>
      </div>
      {lignes.length === 0 ? (
        <p className="px-5 py-8 text-center text-sm font-medium text-muted">
          Rien de planifié pour les jours à venir.
        </p>
      ) : (
        <div className="mt-3 overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr>
                <th className="text-left font-bold text-heading">Date</th>
                <th className="text-left font-bold text-heading">Nature</th>
                <th className="text-left font-bold text-heading">Intitulé</th>
                <th className="text-left font-bold text-heading">Classe</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {lignes.map((l, i) => {
                const nature = NATURES[l.nature] ?? { libelle: l.nature, classes: 'bg-slate-100 text-slate-700' }
                return (
                  <tr key={`${l.date}-${l.titre}-${i}`}>
                    <td className="whitespace-nowrap font-semibold text-heading">
                      {new Date(l.date).toLocaleDateString('fr-FR', {
                        day: '2-digit',
                        month: 'short',
                        year: 'numeric',
                      })}
                    </td>
                    <td>
                      <span className={`rounded-full px-2 py-0.5 text-xs font-bold ${nature.classes}`}>
                        {nature.libelle}
                      </span>
                    </td>
                    <td className="font-medium text-heading">
                      {l.titre}
                      {l.type && <span className="ml-1.5 text-xs font-semibold text-muted">({l.type})</span>}
                    </td>
                    <td className="font-medium text-heading">{l.classe ?? '—'}</td>
                  </tr>
                )
              })}
            </tbody>
          </table>
        </div>
      )}
    </div>
  )
}

export default function DashboardPage() {
  const { user } = useAuthStore()
  const [vue, setVue] = useState('mouvements')
  const { data: stats, isLoading, error } = useQuery({
    queryKey: ['dashboard-stats'],
    queryFn: fetchDashboardStats,
  })
  // Statistiques illisibles : on le dit, au lieu d'une page de zéros et de « aucune donnée »
  // qu'on prendrait pour la réalité de l'établissement.
  const indisponible = Boolean(error) && !stats

  const effectifs = stats?.effectifs ?? {}
  const vueCourante = VUES_MENSUELLES[vue]
  const donneesMensuelles = stats?.[vueCourante.source] ?? []
  const classes = stats?.moyenne_par_classe ?? []

  return (
    <div>
      {/* Bandeau d'identité : le seul aplat de marque de l'écran. */}
      <div
        className="relative overflow-hidden rounded-2xl p-6 sm:p-8"
        style={{
          background: 'linear-gradient(135deg, var(--brand-surface), var(--brand-surface-2))',
          boxShadow: 'var(--shadow)',
        }}
      >
        <div className="pointer-events-none absolute -right-10 -top-16 h-56 w-56 rounded-full bg-primary-400/30 blur-3xl" />
        <div className="pointer-events-none absolute -bottom-16 left-1/4 h-56 w-56 rounded-full bg-primary-300/20 blur-3xl" />
        <div className="relative flex flex-wrap items-end justify-between gap-4">
          <div>
            <h1 className="text-xl font-bold text-white sm:text-2xl">Bonjour, {user?.name}</h1>
            <p className="mt-1 text-sm font-semibold" style={{ color: 'var(--sidebar-text)' }}>
              {stats?.etablissement?.nom ?? 'Vue société'}
              {stats?.annee_scolaire_active ? ` — ${stats.annee_scolaire_active}` : ''}
            </p>
          </div>
          <div className="text-right">
            <p className="text-4xl font-extrabold leading-none text-white">
              {isLoading ? '…' : indisponible ? '—' : (effectifs.total_eleves ?? 0)}
            </p>
            <p className="text-xs font-bold uppercase tracking-wide" style={{ color: 'var(--sidebar-text)' }}>
              élèves inscrits
            </p>
          </div>
        </div>
      </div>

      {indisponible ? (
        <div className="mt-5"><MessageErreur erreur={error} /></div>
      ) : (
        <>
          <div className="mt-5 grid grid-cols-2 gap-4 lg:grid-cols-4">
            <Tuile icon={Users} valeur={effectifs.total_eleves} libelle="Élèves" />
            <Tuile icon={School} valeur={effectifs.total_classes} libelle="Classes" />
            <Tuile icon={GraduationCap} valeur={effectifs.total_enseignants} libelle="Enseignants" />
            <Tuile
              icon={TrendingUp}
              valeur={stats?.moyenne_generale != null ? `${stats.moyenne_generale}/20` : null}
              libelle="Moyenne générale"
              precision={
                stats?.absences_ce_mois != null
                  ? `${stats.absences_ce_mois} absence${stats.absences_ce_mois > 1 ? 's' : ''} ce mois`
                  : null
              }
            />
          </div>

          <div className="mt-5 grid gap-5 lg:grid-cols-2">
            <CarteGraphique
              titre="Répartition par niveau"
              hauteur={180}
              vide={(stats?.repartition_niveaux?.length ?? 0) === 0}
              messageVide="Aucun élève rattaché à un niveau pour cette année."
            >
              <Suspense fallback={<GraphiqueEnAttente hauteur={180} />}>
                <Anneau donnees={stats?.repartition_niveaux ?? []} cleValeur="effectif" cleNom="niveau" />
              </Suspense>
            </CarteGraphique>

            <CarteGraphique titre="Indicateurs clés" hauteur={180} vide={false}>
              <Suspense fallback={<GraphiqueEnAttente hauteur={180} />}>
                <div className="flex h-full flex-wrap items-center justify-around gap-x-2 gap-y-4">
                  <Jauge
                    valeur={stats?.assiduite?.taux ?? null}
                    couleur="var(--chart-2)"
                    libelle="Assiduité"
                    detail={
                      stats?.assiduite
                        ? `${stats.assiduite.absences} absence${stats.assiduite.absences > 1 ? 's' : ''} / ${stats.assiduite.jours_ouvres} j. ouvrés`
                        : 'Dates de l’année manquantes'
                    }
                  />
                  <Jauge
                    valeur={stats?.taux_reussite?.taux ?? null}
                    couleur="var(--chart-1)"
                    libelle="Réussite"
                    detail={
                      stats?.taux_reussite
                        ? `${stats.taux_reussite.admis} / ${stats.taux_reussite.evalues} ≥ 10`
                        : 'Aucune moyenne calculée'
                    }
                  />
                  <Jauge
                    valeur={stats?.taux_encadrement?.taux ?? null}
                    couleur="var(--chart-3)"
                    libelle="Encadrement"
                    detail={
                      stats?.taux_encadrement
                        ? `${stats.taux_encadrement.couvertes} / ${stats.taux_encadrement.classes} classes`
                        : 'Aucune classe'
                    }
                  />
                </div>
              </Suspense>
            </CarteGraphique>
          </div>

          <div className="mt-5 grid gap-5 lg:grid-cols-3">
            <CarteGraphique
              titre="Moyenne par classe"
              hauteur={240}
              vide={classes.length === 0}
              messageVide="Aucune moyenne calculée pour cette année."
            >
              <ul className="max-h-[280px] space-y-2 overflow-y-auto pr-1">
                {classes.map((c) => (
                  <li key={c.classe} className="rounded-xl surface-2 px-3 py-2">
                    <div className="flex items-center justify-between text-sm">
                      <span className="min-w-0 truncate font-bold text-heading">{c.classe}</span>
                      <span
                        className={`ml-2 shrink-0 rounded-full px-2 py-0.5 text-xs font-bold ${
                          c.moyenne >= 10 ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
                        }`}
                      >
                        {c.moyenne}/20
                      </span>
                    </div>
                    <div className="mt-1.5 h-1.5 overflow-hidden rounded-full bg-slate-200">
                      <div
                        className="h-full rounded-full"
                        style={{
                          width: `${Math.min(100, (c.moyenne / 20) * 100)}%`,
                          background: c.moyenne >= 10 ? 'var(--success)' : 'var(--danger)',
                        }}
                      />
                    </div>
                  </li>
                ))}
              </ul>
            </CarteGraphique>

            <div className="lg:col-span-2">
              <CarteGraphique
                titre="Évolution mensuelle"
                hauteur={280}
                hauteurFixe
                vide={donneesMensuelles.length === 0}
                messageVide={vueCourante.vide}
                actions={
                  <div className="flex gap-1 rounded-lg p-1" style={{ background: 'var(--surface-2)' }}>
                    {Object.entries(VUES_MENSUELLES).map(([cle, v]) => (
                      <button
                        key={cle}
                        type="button"
                        onClick={() => setVue(cle)}
                        className="rounded-md px-2.5 py-1 text-xs font-semibold transition"
                        style={
                          vue === cle
                            ? { background: 'var(--brand-accent)', color: 'var(--brand-accent-ink)' }
                            : { color: 'var(--text)' }
                        }
                      >
                        {v.libelle}
                      </button>
                    ))}
                  </div>
                }
              >
                <Suspense fallback={<GraphiqueEnAttente hauteur={280} />}>
                  <HistogrammeMensuel donnees={donneesMensuelles} series={vueCourante.series} />
                </Suspense>
              </CarteGraphique>
            </div>
          </div>

          <Agenda lignes={stats?.agenda ?? []} />
        </>
      )}
    </div>
  )
}
