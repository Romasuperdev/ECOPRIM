import { useParams, Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, Printer } from 'lucide-react'
import Button from '../../components/ui/Button'
import { fetchBulletinDonnees, fetchEleve, imprimerBulletin } from './elevesApi'

function Champ({ label, value }) {
  return (
    <div>
      <dt className="text-xs font-medium uppercase tracking-wide text-slate-400">{label}</dt>
      <dd className="text-sm text-slate-800">{value || '—'}</dd>
    </div>
  )
}

/** Moyennes par matière + moyenne générale et rang, avec impression du bulletin PDF. */
function SectionBulletin({ eleve }) {
  const { data, isLoading } = useQuery({
    queryKey: ['bulletin-donnees', eleve.id],
    queryFn: () => fetchBulletinDonnees(eleve.id),
    enabled: Boolean(eleve.classe_code),
  })

  return (
    <section className="rounded-xl border border-slate-200 bg-white p-6">
      <div className="mb-4 flex items-center justify-between">
        <h2 className="text-lg font-semibold text-slate-800">Bulletin</h2>
        <Button
          variant="outline" disabled={!eleve.classe_code}
          title={!eleve.classe_code ? 'Élève sans classe : bulletin indisponible.' : undefined}
          onClick={() => imprimerBulletin(eleve.id, eleve.matricule)}
        >
          <Printer size={16} className="mr-1.5 inline" />
          Imprimer le bulletin
        </Button>
      </div>

      {!eleve.classe_code && (
        <p className="text-sm text-slate-400">Cet élève n’est rattaché à aucune classe.</p>
      )}

      {eleve.classe_code && isLoading && <p className="text-sm text-slate-400">Chargement…</p>}

      {eleve.classe_code && data && (
        <>
          <div className="mb-5 grid grid-cols-2 gap-4 sm:grid-cols-3">
            <div className="rounded-lg bg-slate-50 px-4 py-3 text-center">
              <div className="text-2xl font-bold text-slate-800">
                {data.moyenneGenerale !== null ? Number(data.moyenneGenerale).toFixed(2) : '—'}
              </div>
              <div className="text-xs text-slate-400">Moyenne générale / 20</div>
            </div>
            <div className="rounded-lg bg-slate-50 px-4 py-3 text-center">
              <div className="text-2xl font-bold text-slate-800">{data.rang ?? '—'}</div>
              <div className="text-xs text-slate-400">
                Rang{data.effectif ? ` / ${data.effectif}` : ''}
              </div>
            </div>
            <div className="rounded-lg bg-slate-50 px-4 py-3 text-center">
              <div className="text-2xl font-bold text-slate-800">{data.moyennesParMatiere.length}</div>
              <div className="text-xs text-slate-400">Matière(s) évaluée(s)</div>
            </div>
          </div>

          <table className="w-full text-left text-sm">
            <thead className="border-b border-slate-200 text-slate-500">
              <tr>
                <th className="py-2 font-medium">Matière</th>
                <th className="py-2 font-medium">Coef.</th>
                <th className="py-2 font-medium">Notes</th>
                <th className="py-2 font-medium">Moyenne</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100">
              {data.moyennesParMatiere.length === 0 && (
                <tr><td colSpan={4} className="py-4 text-center text-slate-400">Aucune note enregistrée pour cette année.</td></tr>
              )}
              {data.moyennesParMatiere.map((m) => (
                <tr key={m.matiere_code}>
                  <td className="py-2 font-medium text-slate-800">{m.matiere}</td>
                  <td className="py-2 text-slate-600">{m.coefficient ?? '—'}</td>
                  <td className="py-2 text-slate-600">{m.nombre_notes}</td>
                  <td className="py-2 text-slate-600">{m.moyenne !== null ? `${Number(m.moyenne).toFixed(2)}/20` : '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </>
      )}
    </section>
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

      <SectionBulletin eleve={eleve} />

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
