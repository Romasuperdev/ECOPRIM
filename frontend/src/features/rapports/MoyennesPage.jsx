import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Printer, Trophy } from 'lucide-react'
import Button from '../../components/ui/Button'
import Select from '../../components/ui/Select'
import { fetchMoyennesClasse } from './rapportsApi'
import { fetchAllClasses } from '../reference/referenceApi'
import { imprimerBulletin } from '../eleves/elevesApi'

// Lecture seule : moyennes & classement issus d'ECONOMAT (V_MOYENNE_ELEVE_CLASSE).
export default function MoyennesPage() {
  const [classeCode, setClasseCode] = useState('')

  const { data: classes } = useQuery({ queryKey: ['classes', 'all'], queryFn: fetchAllClasses })
  const { data: rapport, isLoading } = useQuery({
    queryKey: ['moyennes', classeCode],
    queryFn: () => fetchMoyennesClasse(classeCode),
    enabled: Boolean(classeCode),
  })

  return (
    <div>
      <h1 className="mb-6 text-2xl font-bold text-slate-800">Résultats &amp; bulletins</h1>

      <div className="mb-6 max-w-sm">
        <Select label="Classe" value={classeCode} onChange={(e) => setClasseCode(e.target.value)}>
          <option value="">— Sélectionner —</option>
          {classes?.map((classe) => (
            <option key={classe.id} value={classe.code}>
              {classe.nom}
            </option>
          ))}
        </Select>
      </div>

      {!classeCode && <p className="text-slate-400">Sélectionne une classe pour voir le classement.</p>}
      {isLoading && <p className="text-slate-400">Chargement…</p>}

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
                  <tr><td colSpan={5} className="px-4 py-6 text-center text-slate-400">Aucune moyenne pour cette classe.</td></tr>
                )}
                {rapport.classement.map((row) => (
                  <tr key={row.code_eleve ?? `${row.matricule}-${row.rang}`} className="hover:bg-slate-50">
                    <td className="px-4 py-3">
                      <span className="flex items-center gap-1 font-medium text-slate-700">
                        {Number(row.rang) <= 3 && <Trophy size={14} className="text-secondary-500" />}
                        {row.rang}
                      </span>
                    </td>
                    <td className="px-4 py-3 font-medium text-slate-800">{row.prenom} {row.nom}</td>
                    <td className="px-4 py-3 text-slate-600">{row.matricule}</td>
                    <td className="px-4 py-3">
                      <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${Number(row.moyenne) >= 10 ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'}`}>
                        {row.moyenne ?? '—'}/20
                      </span>
                    </td>
                    <td className="px-4 py-3 text-right">
                      <Button
                        variant="outline" className="!px-3 !py-1"
                        title="Imprimer le bulletin de cet élève"
                        onClick={() => imprimerBulletin(row.code_eleve, row.matricule)}
                      >
                        <Printer size={14} />
                      </Button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </>
      )}
    </div>
  )
}
