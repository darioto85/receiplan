<?php

namespace App\Entity;

use App\Repository\PreinscriptionRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: PreinscriptionRepository::class)]
#[ORM\Table(name: 'preinscription')]
#[ORM\UniqueConstraint(name: 'uniq_preinscription_email', columns: ['email'])]
#[UniqueEntity(fields: ['email'], message: 'Cet email est déjà inscrit.')]
class Preinscription
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank(message: 'Veuillez saisir un email.')]
    #[Assert\Email(message: 'Veuillez saisir un email valide.')]
    private ?string $email = null;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;

    // Optionnel plus tard
    // #[ORM\Column(length: 32, nullable: true)]
    // private ?string $source = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;

        return $this;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    // public function getSource(): ?string { return $this->source; }
    // public function setSource(?string $source): self { $this->source = $source; return $this; }
}