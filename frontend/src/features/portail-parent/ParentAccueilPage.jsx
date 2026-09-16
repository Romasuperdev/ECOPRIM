import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router-dom'
import { CalendarX, ChevronRight, ClipboardList, TrendingUp } from 'lucide-react'
import BandeauPortail from '../../components/ui/BandeauPortail'
import { fetchMesEnfants } from './portailParentApi'

/** Initiales, à défaut d'une photo : mieux qu'une silhouette grise identique pour tous. */
function Initiales({ prenom, nom }) {
  const lettres = `${(prenom ?? '').trim()[0] ?? ''}${(nom ?? '').trim()[0] ?? ''}`.toUpperCase()

  return (
    <span
      className="pastille-relief flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl text-base font-extrabold"
      style={{
        '--pastille-haut': 'var(--module-parametre-haut)',
        '--pastille-bas': 'var(--module-parametre-bas)',
        '--pastille-encre': 'var(--module-parametre-encre)',
      }}
    >
      {lettres || '?'}
    </span>
  )
}

/**
 * Un chiffre de l'aperçu. `ton` porte le sens : le vert dit « rien à signaler »,
 * l'orange « à regarder ». Une moyenne sous 10 ou des absences appellent le second.
 */
function Chiffre({ icon: Icone, valeur, libelle, ton = 'neutre' }) {
  const tons = {
    neutre: { fond: 'var(--surface-2)', texte: 'var(--heading)' },
    bon: { fond: 'var(--success-bg)', texte: 'var(--success-text)' },
    attention: { fond: 'var(--warning-bg)', texte: 'var(--warning-text)' },
  }
  const { fond, texte } = tons[ton] ?? tons.neutre

  return (
    <div className="flex-1 rounded-xl px-2.5 py-2 text-center" style={{ background: fond }}>
      <div className="flex items-center justify-center gap-1 text-sm font-extrabold" style={{ color: texte }}>
        <Icone size={14} strokeWidth={2.6} />
        {valeur}
      </div>
      <div className="mt-0.5 text-[11px] font-bold text-muted">{libelle}</div>
    </div>
  )
}

function CarteEnfant({ enfant }) {
  const a = enfant.apercu ?? {}
  const moyenne = a.moyenne
  const absences = a.absences
  const devoirs = a.devoirs_a_venir

  return (
    <Link
      to={`/mon-espace/enfants/${enfant.matricule}`}
      className="card flex flex-col gap-3.5 rounded-2xl p-4 transition hover:shadow-md"
    >
      <div className="flex items-center gap-3.5">
        <Initiales prenom={enfant.prenom} nom={enfant.nom} />
        <div className="min-w-0 flex-1">
          <div className="truncate text-base font-bold text-heading">
            {`${enfant.prenom ?? ''} ${enfant.nom ?? ''}`.trim() || enfant.matricule}
          </div>
          <div className="truncate text-sm font-semibold text-muted">
            {enfant.classe_libelle ?? enfant.classe ?? '—'}
          </div>
          {enfant.enseignant_titulaire && (
            <div className="truncate text-xs font-medium text-muted">
              Titulaire : {enfant.enseignant_titulaire}
            </div>
          )}
        </div>
        <ChevronRight size={20} strokeWidth={2.5} className="shrink-0 text-muted" />
      </div>

      <div className="flex gap-2">
        <Chiffre
          icon={TrendingUp}
          valeur={moyenne != null ? `${moyenne}/20` : '—'}
          libelle="Moyenne"
          ton={moyenne == null ? 'neutre' : moyenne >= 10 ? 'bon' : 'attention'}
        />
        <Chiffre
          icon={CalendarX}
          valeur={absences ?? '—'}
          libelle="Absences"
          ton={absences == null ? 'neutre' : absences === 0 ? 'bon' : 'attention'}
        />
        <Chiffre
          icon={ClipboardList}
          valeur={devoirs ?? '—'}
          libelle="Devoirs à venir"
          ton={devoirs ? 'attention' : 'neutre'}
        />
      </div>
    </Link>
  )
}

/** Accueil du portail Parent : mes enfants rattachés, pour l'année en cours. */
export default function ParentAccueilPage() {
  const { data, isLoading } = useQuery({ queryKey: ['mes-enfants'], queryFn: fetchMesEnfants })

  const enfants = data?.enfants ?? []

  return (
    <div className="space-y-5">
      <BandeauPortail
        titre={enfants.length > 1 ? 'Mes enfants' : 'Mon enfant'}
        sousTitre={`Année ${data?.annee ?? 'en cours'} — résultats, absences, devoirs et documents.`}
        droite={
          enfants.length > 1 && (
            <>
              <p className="text-3xl font-extrabold leading-none text-white">{enfants.length}</p>
              <p className="text-xs font-bold uppercase tracking-wide" style={{ color: 'var(--sidebar-text)' }}>
                enfants
              </p>
            </>
          )
        }
      />

      {isLoading && <p className="font-medium text-muted">Chargement…</p>}

      {!isLoading && enfants.length === 0 && (
        <p className="card rounded-2xl px-4 py-10 text-center text-sm font-medium text-muted">
          Aucun enfant ne vous est encore rattaché. Rapprochez-vous de l’administration.
        </p>
      )}

      <div className="grid gap-4 sm:grid-cols-2">
        {enfants.map((e) => (
          <CarteEnfant key={e.matricule} enfant={e} />
        ))}
      </div>
    </div>
  )
}
