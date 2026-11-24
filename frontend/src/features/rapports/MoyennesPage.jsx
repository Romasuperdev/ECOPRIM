import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Printer, Trophy } from 'lucide-react'
import Button from '../../components/ui/Button'
import Select from '../../components/ui/Select'
import { fetchMoyennesClasse } from './rapportsApi'
import { fetchAllClasses } from '../reference/referenceApi'
import { imprimerBulletin } from '../eleves/elevesApi'

const nombre = (v) => (v === null || v === undefined ? '—' : String(v).replace('.', ','))

// Moyennes & classement d'une classe. Celles d'ECONOMAT quand il les a calculées ;
// sinon, celles que NEXORA calcule à partir des notes saisies (rapport.source).
export default function MoyennesPage() {
  const [classeCode, setClasseCode] = useState('')
  const [session, setSession] = useState('')

  const { data: classes } = useQuery({ queryKey: ['classes', 'all'], queryFn: fetchAllClasses })
  const { data: rapport, isLoading } = useQuery({
    queryKey: ['moyennes', classeCode, session],
    queryFn: () => fetchMoyennesClasse(classeCode, session),
    enabled: Boolean(classeCode),
  })

  const matieres = rapport?.matieres ?? []
  const notees = rapport?.classement.filter((row) => row.moyenne !== null) ?? []
  const admis = notees.filter((row) => Number(row.moyenne) >= 10).length
  const colonnes = 5 + matieres.length + (matieres.length ? 1 : 0)

  return (
    <div>
      <h1 className="mb-6 text-2xl font-bold text-slate-800">Résultats &amp; bulletins</h1>

      <div className="mb-6 grid max-w-lg grid-cols-2 gap-4">
        <Select
          label="Classe" value={classeCode}
          onChange={(e) => { setClasseCode(e.target.value); setSession('') }}
        >
          <option value="">— Sélectionner —</option>
          {classes?.map((classe) => (
            <option key={classe.id} value={classe.code}>
              {classe.nom}
            </option>
          ))}
        </Select>
        <Select
          label="Session" value={session || rapport?.session || ''}
          onChange={(e) => setSession(e.target.value)}
          disabled={!rapport?.sessions?.length}
        >
          {!rapport?.sessions?.length && <option value="">—</option>}
          {rapport?.sessions?.map((s) => (
            <option key={s} value={s}>{s}</option>
          ))}
        </Select>
      </div>

      {!classeCode && <p className="text-muted">Sélectionne une classe pour voir le classement.</p>}
      {isLoading && <p className="text-muted">Chargement…</p>}

      {rapport && (
        <>
          <div className="mb-4 grid gap-4 sm:grid-cols-3">
            <div className="rounded-xl border border-slate-200 bg-card p-5">
              <p className="text-sm text-slate-500">Moyenne de la classe {rapport.classe}</p>
              <p className="text-2xl font-bold text-slate-800">
                {rapport.moyenne_classe !== null ? `${nombre(rapport.moyenne_classe)}/20` : '—'}
              </p>
            </div>
            <div className="rounded-xl border border-slate-200 bg-card p-5">
              <p className="text-sm text-slate-500">Élèves notés</p>
              <p className="text-2xl font-bold text-slate-800">
                {notees.length} / {rapport.effectif ?? rapport.classement.length}
              </p>
            </div>
            <div className="rounded-xl border border-slate-200 bg-card p-5">
              <p className="text-sm text-slate-500">Moyenne ≥ 10</p>
              <p className="text-2xl font-bold text-slate-800">
                {notees.length ? `${admis} (${Math.round((admis / notees.length) * 100)} %)` : '—'}
              </p>
            </div>
          </div>

          <p className="mb-3 text-sm text-slate-500">
            {rapport.source === 'economat'
              ? 'Moyennes et rangs calculés par ECONOMAT.'
              : 'Moyennes calculées à partir des notes saisies : total des points obtenus rapporté au total possible, sur 20. Une matière sans note ne compte pas.'}
          </p>

          <div className="overflow-x-auto rounded-xl border border-slate-200 bg-card">
            <table className="w-full text-left text-sm">
              <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
                <tr>
                  <th className="px-4 py-3 font-medium">Rang</th>
                  <th className="px-4 py-3 font-medium">Élève</th>
                  <th className="px-4 py-3 font-medium">Matricule</th>
                  {matieres.map((m) => (
                    <th key={m.code} className="px-3 py-3 text-center font-medium" title={m.libelle}>
                      <span className="block whitespace-nowrap">{m.libelle}</span>
                      <span className="text-xs font-normal">/{nombre(m.bareme)}</span>
                    </th>
                  ))}
                  {matieres.length > 0 && <th className="px-3 py-3 text-center font-medium">Total</th>}
                  <th className="px-4 py-3 font-medium">Moyenne</th>
                  <th className="px-4 py-3 font-medium text-right">Bulletin</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100">
                {rapport.classement.length === 0 && (
                  <tr><td colSpan={colonnes} className="px-4 py-6 text-center text-muted">Aucun élève dans cette classe.</td></tr>
                )}
                {rapport.classement.map((row) => (
                  <tr key={row.matricule ?? row.code_eleve} className="hover:bg-slate-50">
                    <td className="px-4 py-3">
                      <span className="flex items-center gap-1 font-medium text-slate-700">
                        {row.rang && Number(row.rang) <= 3 && <Trophy size={14} className="text-secondary-500" />}
                        {row.rang ?? '—'}
                      </span>
                    </td>
                    <td className="px-4 py-3 font-medium text-slate-800">{row.nom} {row.prenom}</td>
                    <td className="px-4 py-3 text-slate-600">{row.matricule}</td>
                    {matieres.map((m) => (
                      <td key={m.code} className="px-3 py-3 text-center text-slate-700">
                        {nombre(row.notes?.[m.code])}
                      </td>
                    ))}
                    {matieres.length > 0 && (
                      <td className="whitespace-nowrap px-3 py-3 text-center text-slate-700">
                        {row.total !== null ? `${nombre(row.total)}/${nombre(row.total_max)}` : '—'}
                      </td>
                    )}
                    <td className="px-4 py-3">
                      {row.moyenne !== null ? (
                        <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${Number(row.moyenne) >= 10 ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-700'}`}>
                          {nombre(row.moyenne)}/20
                        </span>
                      ) : (
                        <span className="text-xs text-muted">Pas de note</span>
                      )}
                      {matieres.length > 0 && row.moyenne !== null && row.matieres_notees < matieres.length && (
                        <span className="ml-2 text-xs text-amber-700" title="Matières notées pour cet élève">
                          {row.matieres_notees}/{matieres.length} mat.
                        </span>
                      )}
                    </td>
                    <td className="px-4 py-3 text-right">
                      <Button
                        variant="outline" className="!px-3 !py-1"
                        title="Imprimer le bulletin de cet élève"
                        disabled={!row.code_eleve}
                        onClick={() => imprimerBulletin(row.code_eleve, row.matricule, rapport.session)}
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
