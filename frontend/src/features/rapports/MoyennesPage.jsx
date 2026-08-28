import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Trophy, FileDown } from 'lucide-react'
import Select from '../../components/ui/Select'
import { fetchMoyennesClasse, bulletinDownloadUrl } from './rapportsApi'
import { fetchAllClasses, fetchPeriodes } from '../reference/referenceApi'

export default function MoyennesPage() {
  const [classeId, setClasseId] = useState('')
  const [periodeId, setPeriodeId] = useState('')

  const { data: classes } = useQuery({ queryKey: ['classes', 'all'], queryFn: fetchAllClasses })
  const { data: periodes } = useQuery({ queryKey: ['periodes'], queryFn: () => fetchPeriodes() })

  const { data: rapport, isLoading } = useQuery({
    queryKey: ['moyennes', classeId, periodeId],
    queryFn: () => fetchMoyennesClasse(classeId, periodeId || undefined),
    enabled: Boolean(classeId),
  })

  return (
    <div>
      <h1 className="mb-6 text-2xl font-bold text-slate-800">Moyennes & Classements</h1>

      <div className="mb-6 grid max-w-lg grid-cols-2 gap-4">
        <Select label="Classe" value={classeId} onChange={(e) => setClasseId(e.target.value)}>
          <option value="">— Sélectionner —</option>
          {classes?.map((classe) => (
            <option key={classe.id} value={classe.id}>
              {classe.nom}
            </option>
          ))}
        </Select>
        <Select label="Période" value={periodeId} onChange={(e) => setPeriodeId(e.target.value)}>
          <option value="">Toutes périodes</option>
          {periodes?.map((periode) => (
            <option key={periode.id} value={periode.id}>
              {periode.libelle}
            </option>
          ))}
        </Select>
      </div>

      {!classeId && <p className="text-slate-400">Sélectionne une classe pour voir le classement.</p>}
      {isLoading && <p className="text-slate-400">Calcul en cours…</p>}

      {rapport && (
        <>
          <div className="mb-4 rounded-xl border border-slate-200 bg-white p-5">
            <p className="text-sm text-slate-500">Moyenne de la classe {rapport.classe}</p>
            <p className="text-2xl font-bold text-slate-800">
              {rapport.moyenne_classe !== null ? `${rapport.moyenne_classe}/20` : '—'}
            </p>
          </div>

          <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
                <tr>
                  <th className="px-4 py-3 font-medium">Rang</th>
                  <th className="px-4 py-3 font-medium">Élève</th>
                  <th className="px-4 py-3 font-medium">Matricule</th>
                  <th className="px-4 py-3 font-medium">Moyenne</th>
                  <th className="px-4 py-3 font-medium text-right">Bulletin</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rapport.classement.length === 0 && (
                  <tr>
                    <td colSpan={5} className="px-4 py-6 text-center text-slate-400">
                      Aucune note pour cette sélection.
                    </td>
                  </tr>
                )}
                {rapport.classement.map((row) => (
                  <tr key={row.eleve_id} className="hover:bg-slate-50">
                    <td className="px-4 py-3">
                      <span className="flex items-center gap-1 font-medium text-slate-700">
                        {row.rang <= 3 && <Trophy size={14} className="text-secondary-500" />}
                        {row.rang}
                      </span>
                    </td>
                    <td className="px-4 py-3 font-medium text-slate-800">
                      {row.prenom} {row.nom}
                    </td>
                    <td className="px-4 py-3 text-slate-600">{row.matricule}</td>
                    <td className="px-4 py-3">
                      <span
                        className={`rounded-full px-2 py-0.5 text-xs font-semibold ${
                          row.moyenne >= 10 ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'
                        }`}
                      >
                        {row.moyenne}/20
                      </span>
                    </td>
                    <td className="px-4 py-3 text-right">
                      <a
                        href={bulletinDownloadUrl(row.eleve_id, periodeId || undefined)}
                        className="inline-flex items-center gap-1 rounded p-1.5 text-slate-500 hover:bg-slate-100 hover:text-primary-700"
                      >
                        <FileDown size={16} />
                      </a>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {rapport.sans_note.length > 0 && (
            <p className="mt-3 text-sm text-slate-400">
              {rapport.sans_note.length} élève(s) sans note pour cette sélection :{' '}
              {rapport.sans_note.map((e) => `${e.prenom} ${e.nom}`).join(', ')}
            </p>
          )}
        </>
      )}
    </div>
  )
}
