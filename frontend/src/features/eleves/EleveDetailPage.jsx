import { useParams, Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft } from 'lucide-react'
import { fetchEleve } from './elevesApi'

function Champ({ label, value }) {
  return (
    <div>
      <dt className="text-xs font-medium uppercase tracking-wide text-slate-400">{label}</dt>
      <dd className="text-sm text-slate-800">{value || '—'}</dd>
    </div>
  )
}

// Fiche élève en lecture seule (ECONOMAT.T_ETUDIANT).
export default function EleveDetailPage() {
  const { id } = useParams()
  const { data: eleve, isLoading } = useQuery({ queryKey: ['eleves', id], queryFn: () => fetchEleve(id) })

  if (isLoading) return <p className="text-slate-400">Chargement…</p>
  if (!eleve) return <p className="text-slate-400">Élève introuvable.</p>

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-3">
        <Link to="/eleves" className="rounded p-1.5 text-slate-500 hover:bg-slate-100">
          <ArrowLeft size={18} />
        </Link>
        <h1 className="text-2xl font-bold text-slate-800">
          {eleve.prenom} {eleve.nom}
        </h1>
      </div>

      <section className="rounded-xl border border-slate-200 bg-white p-6">
        <h2 className="mb-4 text-lg font-semibold text-slate-800">Identité</h2>
        <dl className="grid grid-cols-2 gap-4 md:grid-cols-3">
          <Champ label="Matricule" value={eleve.matricule} />
          <Champ label="Sexe" value={eleve.sexe} />
          <Champ label="Date de naissance" value={eleve.date_naissance} />
          <Champ label="Lieu de naissance" value={eleve.lieu_naissance} />
          <Champ label="Nationalité" value={eleve.nationalite} />
          <Champ label="Redoublant" value={eleve.redoublant} />
        </dl>
      </section>

      <section className="rounded-xl border border-slate-200 bg-white p-6">
        <h2 className="mb-4 text-lg font-semibold text-slate-800">Scolarité</h2>
        <dl className="grid grid-cols-2 gap-4 md:grid-cols-3">
          <Champ label="Classe" value={eleve.classe?.nom ?? eleve.classe_code} />
          <Champ label="Niveau" value={eleve.niveau_code} />
          <Champ label="Année" value={eleve.annee} />
          <Champ label="Statut" value={eleve.statut} />
        </dl>
      </section>

      <div className="grid gap-6 md:grid-cols-2">
        <section className="rounded-xl border border-slate-200 bg-white p-6">
          <h2 className="mb-4 text-lg font-semibold text-slate-800">Père / Tuteur</h2>
          <dl className="space-y-3">
            <Champ label="Nom" value={`${eleve.pere_prenom ?? ''} ${eleve.pere_nom ?? ''}`.trim()} />
            <Champ label="Profession" value={eleve.pere_profession} />
            <Champ label="Téléphone" value={eleve.pere_telephone} />
            <Champ label="Email" value={eleve.pere_email} />
          </dl>
        </section>
        <section className="rounded-xl border border-slate-200 bg-white p-6">
          <h2 className="mb-4 text-lg font-semibold text-slate-800">Mère</h2>
          <dl className="space-y-3">
            <Champ label="Nom" value={`${eleve.mere_prenom ?? ''} ${eleve.mere_nom ?? ''}`.trim()} />
            <Champ label="Profession" value={eleve.mere_profession} />
            <Champ label="Téléphone" value={eleve.mere_telephone} />
            <Champ label="Email" value={eleve.mere_email} />
          </dl>
        </section>
      </div>
    </div>
  )
}
