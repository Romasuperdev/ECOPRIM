import { useParams, Link } from 'react-router-dom'
import { useQuery } from '@tanstack/react-query'
import { ArrowLeft } from 'lucide-react'
import { fetchEtablissementParCode } from './adminApi'

function Section({ titre, children }) {
  return (
    <section className="rounded-xl border border-slate-200 bg-white p-6">
      <h2 className="mb-4 border-b border-slate-100 pb-2 text-xs font-semibold uppercase tracking-wide text-slate-400">
        {titre}
      </h2>
      <dl className="grid grid-cols-1 gap-4 sm:grid-cols-2">{children}</dl>
    </section>
  )
}

function Champ({ label, value, mono = false, lien }) {
  return (
    <div>
      <dt className="text-xs font-medium uppercase tracking-wide text-slate-400">{label}</dt>
      <dd className={`text-sm text-slate-800 ${mono ? 'font-mono' : ''}`}>
        {value
          ? (lien ? <a href={lien} className="text-primary-700 hover:underline">{value}</a> : value)
          : <span className="italic text-slate-300">Non renseigné</span>}
      </dd>
    </div>
  )
}

export default function EtablissementDetailPage() {
  const { code } = useParams()
  const { data: e, isLoading } = useQuery({
    queryKey: ['etablissement', code],
    queryFn: () => fetchEtablissementParCode(code),
  })

  if (isLoading) return <p className="text-slate-400">Chargement…</p>
  if (!e) return <p className="text-slate-400">Établissement introuvable.</p>

  return (
    <div className="space-y-6">
      <div className="flex items-center gap-3">
        <Link to="/admin/etablissements" className="rounded p-1.5 text-slate-500 hover:bg-slate-100">
          <ArrowLeft size={18} />
        </Link>
        <div>
          <h1 className="text-2xl font-bold text-slate-800">{e.intitule}</h1>
          <p className="font-mono text-xs text-slate-400">{e.code}</p>
        </div>
        <div className="ml-auto flex gap-2">
          <span className={`rounded-full px-2.5 py-1 text-xs font-medium ${e.repris ? 'bg-primary-50 text-primary-700' : 'bg-amber-50 text-amber-700'}`}>
            {e.source}
          </span>
          <span className={`rounded-full px-2.5 py-1 text-xs font-medium ${e.actif ? 'bg-green-50 text-green-700' : 'bg-slate-100 text-slate-500'}`}>
            {e.actif ? 'Actif' : 'Inactif'}
          </span>
        </div>
      </div>

      <Section titre="Identification">
        <Champ label="Code" value={e.code} mono />
        <Champ label="Intitulé" value={e.intitule} />
        <Champ label="Type" value={e.type} />
      </Section>

      <Section titre="Localisation">
        <Champ label="Adresse" value={e.adresse} />
        <Champ label="Ville" value={e.ville} />
        <Champ label="Pays" value={e.pays} />
      </Section>

      <Section titre="Coordonnées">
        <Champ label="Téléphone" value={e.telephone} />
        <Champ label="Email" value={e.email} lien={e.email ? `mailto:${e.email}` : null} />
        <Champ label="Site web" value={e.site_web} lien={e.site_web} />
      </Section>

      <Section titre="Rattachement">
        <Champ label="Code société" value={e.societe_code} mono />
        <Champ label="Société" value={e.societe?.nom} />
      </Section>
    </div>
  )
}
