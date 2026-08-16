<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\UserRepository;
use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use InvalidArgumentException;
use LogicException;

use function sprintf;

use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * An account that can sign into the administration area and be credited as an
 * author.
 *
 * The table is app_user rather than user: "user" is reserved in PostgreSQL, and
 * a table name that has to be quoted in every hand-written query is a papercut
 * with no upside.
 *
 * The security interfaces are implemented here even though sign-in belongs to a
 * later feature. They come from symfony/security-core, which knows nothing of
 * HTTP, sessions or templates — the delivery half lives in security-http and
 * security-bundle — so the domain layer stays independent of delivery.
 */
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: 'app_user')]
#[UniqueEntity(fields: ['email'], message: 'An account with this email address already exists.')]
class User implements PasswordAuthenticatedUserInterface, UserInterface
{
    public const string ROLE_ADMIN = 'ROLE_ADMIN';

    public const string ROLE_EDITOR = 'ROLE_EDITOR';

    public const string ROLE_AUTHOR = 'ROLE_AUTHOR';

    /**
     * Symfony treats this as the baseline every authenticated account holds, so
     * it is appended on read rather than stored.
     */
    private const string ROLE_DEFAULT = 'ROLE_USER';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Email]
    #[Assert\Length(max: 180)]
    private string $email;

    /**
     * The hash, never the password. Empty until one is set, which is a state no
     * account can authenticate from — an empty hash matches nothing.
     */
    #[ORM\Column(length: 255)]
    private string $password = '';

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $roles = [];

    /**
     * The email address goes through its setter rather than being promoted,
     * because it is the one field with a precondition: an account with no
     * address cannot be identified, and getUserIdentifier() promises a
     * non-empty string.
     */
    public function __construct(
        string $email,
        #[ORM\Column(length: 100)]
        #[Assert\NotBlank]
        #[Assert\Length(max: 100)]
        private string $displayName,
        #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
        private readonly DateTimeImmutable $createdAt,
    ) {
        $this->setEmail($email);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): void
    {
        $email = trim($email);

        if ('' === $email) {
            throw new InvalidArgumentException('An account needs an email address to be identified by.');
        }

        $this->email = $email;
    }

    /**
     * The value Symfony authenticates against. Identical to the email address.
     *
     * The constructor and the setter both refuse an empty address, so the guard
     * below can only fire for a row that reached the table another way — a hand
     * written UPDATE, or a restore from a corrupted dump. Handing Symfony an
     * empty identifier in that case would silently break authentication in a
     * place a long way from the cause, so it is reported here instead.
     *
     * @return non-empty-string
     */
    public function getUserIdentifier(): string
    {
        $identifier = $this->email;

        if ('' === $identifier) {
            throw new LogicException(sprintf('Account #%s has no email address to be identified by.', $this->id ?? 'new'));
        }

        return $identifier;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $hashedPassword): void
    {
        $this->password = $hashedPassword;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function setDisplayName(string $displayName): void
    {
        $this->displayName = $displayName;
    }

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        $roles[] = self::ROLE_DEFAULT;

        return array_values(array_unique($roles));
    }

    /**
     * @param list<string> $roles
     */
    public function setRoles(array $roles): void
    {
        $this->roles = array_values(array_unique($roles));
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }
}
