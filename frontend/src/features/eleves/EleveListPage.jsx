import { useState } from 'react'
import { Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { Eye } from 'lucide-react'
import Button from '../../components/ui/Button'
import { fetchEleves } from './elevesApi'

// Lecture seule : élèves issus d'ECONOMAT (T_ETUDIANT).
export default function EleveListPage() {
  const [page, setPage] = useState(1)
  const { data, isLoading } = useQuery({ queryKey: ['eleves', page], queryFn: () => fetchEleves(page) })

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-slate-800">Élèves</h1>
        <p className="mt-1 text-sm text-slate-500">Consultation (source : ECONOMAT).</p>
      </div>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Matricule</th>
              <th className="px-4 py-3 font-medium">Nom</th>
              <th className="px-4 py-3 font-medium">Prénom</th>
              <th className="px-4 py-3 font-medium">Classe</th>
              <th className="px-4 py-3 font-medium">Statut</th>
              <th className="px-4 py-3 font-medium text-right">Fiche</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && (
              <tr><td colSpan={6} className="px-4 py-6 text-center text-slate-400">Chargement…</td></tr>
            )}
            {!isLoading && data?.data?.length === 0 && (
              <tr><td colSpan={6} className="px-4 py-6 text-center text-slate-400">Aucun élève.</td></tr>
            )}
            {data?.data?.map((eleve) => (
              <tr key={eleve.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 text-slate-600">{eleve.matricule}</td>
                <td className="px-4 py-3 font-medium text-slate-800">{eleve.nom}</td>
                <td className="px-4 py-3 text-slate-600">{eleve.prenom}</td>
                <td className="px-4 py-3 text-slate-600">{eleve.classe?.nom ?? eleve.classe_code ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{eleve.statut ?? '—'}</td>
                <td className="px-4 py-3 text-right">
                  <Link to={`/eleves/${eleve.id}`} className="inline-flex rounded p-1.5 text-slate-500 hover:bg-slate-100 hover:text-primary-700">
                    <Eye size={16} />
                  </Link>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {data && (
        <div className="mt-4 flex justify-end gap-2">
          <Button variant="outline" disabled={!data.prev_page_url} onClick={() => setPage((p) => p - 1)}>Précédent</Button>
          <Button variant="outline" disabled={!data.next_page_url} onClick={() => setPage((p) => p + 1)}>Suivant</Button>
        </div>
      )}
    </div>
  )
}
