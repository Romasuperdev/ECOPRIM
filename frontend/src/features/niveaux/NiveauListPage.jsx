import { useQuery } from '@tanstack/react-query'
import { fetchNiveaux } from '../reference/referenceApi'

// Lecture seule : cycles & niveaux proviennent d'ECONOMAT (T_NIVEAU / T_CYCLE).
export default function NiveauListPage() {
  const { data: niveaux, isLoading } = useQuery({ queryKey: ['niveaux'], queryFn: fetchNiveaux })

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-slate-800">Cycles / Niveaux</h1>
        <p className="mt-1 text-sm text-slate-500">Consultation (source : ECONOMAT).</p>
      </div>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Ordre</th>
              <th className="px-4 py-3 font-medium">Code</th>
              <th className="px-4 py-3 font-medium">Libellé</th>
              <th className="px-4 py-3 font-medium">Cycle</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && (
              <tr><td colSpan={4} className="px-4 py-6 text-center text-slate-400">Chargement…</td></tr>
            )}
            {!isLoading && niveaux?.length === 0 && (
              <tr><td colSpan={4} className="px-4 py-6 text-center text-slate-400">Aucun niveau.</td></tr>
            )}
            {niveaux?.map((niveau) => (
              <tr key={niveau.id} className="hover:bg-slate-50">
                <td className="px-4 py-3 text-slate-600">{niveau.ordre ?? '—'}</td>
                <td className="px-4 py-3 font-medium text-slate-800">{niveau.code}</td>
                <td className="px-4 py-3 text-slate-600">{niveau.libelle}</td>
                <td className="px-4 py-3 text-slate-600">{niveau.cycle?.libelle ?? niveau.cycle_code ?? '—'}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  )
}
