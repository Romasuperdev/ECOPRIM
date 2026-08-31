import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import Select from '../../components/ui/Select'
import Button from '../../components/ui/Button'
import apiClient from '../../api/client'
import { fetchAllClasses } from '../reference/referenceApi'

async function fetchAbsencesConsultation({ classe_code, page }) {
  const { data } = await apiClient.get('/absences', { params: { classe_code, page } })
  return data
}

// Lecture seule : absences élèves issues d'ECONOMAT (T_ABSENCEELEVE).
export default function AbsenceListPage() {
  const [classeCode, setClasseCode] = useState('')
  const [page, setPage] = useState(1)

  const { data: classes } = useQuery({ queryKey: ['classes', 'all'], queryFn: fetchAllClasses })
  const { data, isLoading } = useQuery({
    queryKey: ['absences', classeCode, page],
    queryFn: () => fetchAbsencesConsultation({ classe_code: classeCode || undefined, page }),
  })

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-slate-800">Absences</h1>
        <p className="mt-1 text-sm text-slate-500">Consultation (source : ECONOMAT).</p>
      </div>

      <div className="mb-6 max-w-xs">
        <Select label="Classe (optionnel)" value={classeCode} onChange={(e) => { setClasseCode(e.target.value); setPage(1) }}>
          <option value="">Toutes les classes</option>
          {classes?.map((classe) => (
            <option key={classe.id} value={classe.code}>{classe.nom}</option>
          ))}
        </Select>
      </div>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Élève</th>
              <th className="px-4 py-3 font-medium">Classe</th>
              <th className="px-4 py-3 font-medium">Date</th>
              <th className="px-4 py-3 font-medium">Motif</th>
              <th className="px-4 py-3 font-medium">Justifiée</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && (
              <tr><td colSpan={5} className="px-4 py-6 text-center text-slate-400">Chargement…</td></tr>
            )}
            {!isLoading && data?.data?.length === 0 && (
              <tr><td colSpan={5} className="px-4 py-6 text-center text-slate-400">Aucune absence.</td></tr>
            )}
            {data?.data?.map((a) => (
              <tr key={a.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 font-medium text-slate-800">
                  {a.eleve ? `${a.eleve.prenom ?? ''} ${a.eleve.nom ?? ''}`.trim() : a.matricule}
                </td>
                <td className="px-4 py-3 text-slate-600">{a.classe_code ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{a.date ?? '—'}</td>
                <td className="px-4 py-3 text-slate-600">{a.motif ?? '—'}</td>
                <td className="px-4 py-3">
                  {a.justifiee
                    ? <span className="rounded-full bg-green-50 px-2 py-0.5 text-xs font-medium text-green-700">Oui</span>
                    : <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-medium text-slate-500">Non</span>}
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
