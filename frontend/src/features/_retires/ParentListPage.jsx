import { useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { Info, Mail, Phone } from 'lucide-react'
import Input from '../../components/ui/Input'
import Select from '../../components/ui/Select'
import { fetchAllClasses } from '../reference/referenceApi'
import { fetchContexte } from '../contexte/contexteApi'
import { fetchParents } from './parentsApi'

/**
 * Annuaire des parents et tuteurs, dérivé des fiches élèves (ECONOMAT.T_ETUDIANT).
 *
 * Il n'y a pas de table de parents : le père/tuteur et la mère sont des colonnes de la
 * fiche élève, saisies à l'inscription. Cette page les regroupe — un parent, ses
 * coordonnées, ses enfants inscrits — et reste en lecture seule : on corrige une
 * coordonnée là où elle vit, dans Inscriptions.
 */
export default function ParentListPage() {
  const [filtres, setFiltres] = useState({ q: '', classe: '', lien: '' })

  const { data: contexte } = useQuery({ queryKey: ['contexte'], queryFn: fetchContexte, retry: false })
  const { data: classes } = useQuery({ queryKey: ['classes', 'all'], queryFn: fetchAllClasses })
  const { data, isLoading } = useQuery({
    queryKey: ['parents', filtres],
    queryFn: () => fetchParents(filtres),
  })

  const champ = (k, v) => setFiltres((f) => ({ ...f, [k]: v }))
  const parents = data?.data ?? []

  return (
    <div>
      <div className="mb-6">
        <h1 className="text-2xl font-bold text-slate-800">Parents / Tuteurs</h1>
        <p className="mt-1 text-sm text-slate-500">
          Annuaire des familles de l’année {data?.annee ?? contexte?.annee ?? 'en cours'},
          regroupé depuis les fiches élèves.
        </p>
      </div>

      <div className="mb-4 flex items-start gap-2 rounded-xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-600">
        <Info size={16} className="mt-0.5 shrink-0" />
        <span>
          Ces coordonnées viennent de la fiche de chaque élève. Pour en corriger une, passez
          par <strong>Inscriptions</strong> : c’est là que la donnée vit, et il n’y a qu’une
          porte d’écriture sur le dossier élève.
        </span>
      </div>

      <div className="mb-4 flex flex-wrap items-end gap-3">
        <div className="min-w-[220px] flex-1">
          <Input label="Rechercher" placeholder="Nom, téléphone, email…" value={filtres.q}
                 onChange={(e) => champ('q', e.target.value)} />
        </div>
        <div className="min-w-[200px]">
          <Select label="Classe" value={filtres.classe} onChange={(e) => champ('classe', e.target.value)}>
            <option value="">Toutes les classes</option>
            {classes?.map((c) => <option key={c.id} value={c.code}>{c.nom}</option>)}
          </Select>
        </div>
        <div className="min-w-[170px]">
          <Select label="Lien" value={filtres.lien} onChange={(e) => champ('lien', e.target.value)}>
            <option value="">Père, tuteur et mère</option>
            <option value="pere">Père / Tuteur</option>
            <option value="mere">Mère</option>
          </Select>
        </div>
      </div>

      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table className="w-full text-left text-sm">
          <thead className="border-b border-slate-200 bg-slate-50 text-slate-500">
            <tr>
              <th className="px-4 py-3 font-medium">Parent / Tuteur</th>
              <th className="px-4 py-3 font-medium">Lien</th>
              <th className="px-4 py-3 font-medium">Coordonnées</th>
              <th className="px-4 py-3 font-medium">Profession</th>
              <th className="px-4 py-3 font-medium">Enfants inscrits</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading && (
              <tr><td colSpan={5} className="px-4 py-6 text-center text-slate-400">Chargement…</td></tr>
            )}
            {!isLoading && parents.length === 0 && (
              <tr><td colSpan={5} className="px-4 py-6 text-center text-slate-400">Aucun parent renseigné.</td></tr>
            )}
            {parents.map((p) => (
              <tr key={p.cle} className="hover:bg-slate-50">
                <td className="px-4 py-3 font-medium text-slate-800">
                  {`${p.prenom} ${p.nom}`.trim()}
                </td>
                <td className="px-4 py-3 text-slate-600">{p.lien}</td>
                <td className="px-4 py-3 text-slate-600">
                  <div className="space-y-1">
                    {p.telephone && (
                      <div className="flex items-center gap-1.5">
                        <Phone size={13} className="text-slate-400" />
                        <a href={`tel:${p.telephone}`} className="hover:underline">{p.telephone}</a>
                      </div>
                    )}
                    {p.email && (
                      <div className="flex items-center gap-1.5">
                        <Mail size={13} className="text-slate-400" />
                        <a href={`mailto:${p.email}`} className="hover:underline">{p.email}</a>
                      </div>
                    )}
                    {!p.telephone && !p.email && <span className="text-slate-400">—</span>}
                  </div>
                </td>
                <td className="px-4 py-3 text-slate-600">{p.profession || '—'}</td>
                <td className="px-4 py-3 text-slate-600">
                  <div className="flex flex-wrap gap-1.5">
                    {p.enfants.map((e) => (
                      <span key={e.id} className="rounded-full bg-slate-100 px-2 py-0.5 text-xs">
                        {e.nom}{e.classe ? ` · ${e.classe}` : ''}
                      </span>
                    ))}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {data && (
        <p className="mt-3 text-xs text-slate-400">
          {data.total} parent{data.total > 1 ? 's' : ''} ou tuteur{data.total > 1 ? 's' : ''}.
          Le regroupement se fait sur le nom et le téléphone : deux fiches sans téléphone
          restent séparées, pour ne pas fusionner deux familles à tort.
        </p>
      )}
    </div>
  )
}
